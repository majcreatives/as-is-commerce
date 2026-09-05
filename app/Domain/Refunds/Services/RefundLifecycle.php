<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Services;

use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Payments\ValueObjects\ProviderRefund;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Events\RefundStatusChanged;
use App\Models\Refund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The refund state machine, and the order state that follows it.
 *
 * Nothing else in the application writes `refunds.status`. Every move is
 * checked against {@see RefundStatus::allowedTransitions()}, applied in one
 * transaction with whatever it implies for the order, and announced only once
 * that transaction has committed.
 *
 * THERE IS NO METHOD HERE THAT MARKS A REFUND SUCCEEDED ON REQUEST. Both paths
 * into `Succeeded` take a {@see ProviderRefund} -- the provider's own account,
 * fetched server-to-server -- and read the outcome off it. An administrator, a
 * form, or a reference typed in by hand cannot reach it, because no such path
 * exists to be misused. This is the same shape as an order becoming `Paid`,
 * for the same reason.
 *
 * WHEN THE ORDER MOVES, AND WHEN IT DOES NOT.
 *
 *   Paid,
 *   Processing
 *     → Refunded       Only once every pesewa taken for the order has gone
 *                      back. Both states mean the platform held the money and
 *                      the customer never received the goods -- a package
 *                      being packed is still in the building -- so the order
 *                      says what is now true: money came in, nothing was
 *                      delivered, and the money went out again.
 *
 *   Cancelled,
 *   PaymentExpired,
 *   PaymentFailed      Unchanged, always. These orders closed for a reason,
 *                      and a payment that landed afterwards being returned
 *                      does not alter why they closed. Overwriting the status
 *                      would erase the more useful fact. A closed order is
 *                      never resurrected by money moving.
 *
 *   Fulfilled          Unchanged. The customer has the goods; eligibility
 *                      refuses it long before this point, because taking money
 *                      back for something somebody is holding is a return.
 *
 * A PARTIAL REFUND MOVES NOTHING. An order with money still outstanding to it
 * has not finished being refunded, and calling it `Refunded` would overstate
 * what the customer has received.
 *
 * NO CREDITS AND NO STOCK. Nothing here touches the credit ledger in either
 * direction, and nothing posts an inventory movement. Bid credits were
 * consumed when the bids were accepted; a unit that has been sold stays sold
 * until somebody physically returns it, which this platform does not yet do.
 */
class RefundLifecycle
{
    public function __construct(
        private readonly OrderLifecycle $orders,
        private readonly RefundCalculator $calculator,
    ) {}

    /**
     * Record that the provider accepted the refund and has not finished.
     *
     * The honest state for an asynchronous refund. `refunds:reconcile` is what
     * later asks the provider what became of it.
     */
    public function markProcessing(Refund $refund, ProviderRefund $provider): Refund
    {
        $refund = DB::transaction(function () use ($refund, $provider): Refund {
            $locked = $this->lock($refund);

            if ($locked->status !== RefundStatus::Pending) {
                return $locked;
            }

            $locked->provider_reference = $provider->providerReference;
            $locked->provider_status = $provider->status;
            $locked->processed_at = Carbon::now();

            return $this->apply($locked, RefundStatus::Processing);
        });

        $this->announce($refund, RefundStatus::Processing);

        return $refund;
    }

    /**
     * Record that the money went back, on the provider's evidence.
     *
     * The provider's account is required rather than optional: this is the
     * only way into `Succeeded`, and it exists so that nothing can reach it
     * without something authoritative to read the outcome from.
     */
    public function settle(Refund $refund, ProviderRefund $provider): Refund
    {
        $refund = DB::transaction(function () use ($refund, $provider): Refund {
            $locked = $this->lock($refund);

            if (! $locked->status->canTransitionTo(RefundStatus::Succeeded)) {
                // Already settled by a concurrent reconciliation, or already
                // failed. Either way this call has nothing to add.
                return $locked;
            }

            $locked->provider_reference = $provider->providerReference ?? $locked->provider_reference;
            $locked->provider_status = $provider->status;
            $locked->succeeded_at = Carbon::now();
            $locked->processed_at ??= Carbon::now();

            $locked = $this->apply($locked, RefundStatus::Succeeded);

            $this->closeOrderIfFullyRefunded($locked);

            return $locked;
        });

        $this->announce($refund, RefundStatus::Succeeded);

        return $refund;
    }

    /**
     * Record that the refund did not happen, and why.
     *
     * The attempt stays exactly where it is, visible and countable. It is not
     * deleted and not rewritten by a later success: a retry is a new refund
     * with its own row, so the failure remains part of the history.
     *
     * The order is left completely alone. A refund that failed returned
     * nothing, so nothing about the order has changed.
     */
    public function fail(Refund $refund, string $reason, ?ProviderRefund $provider = null): Refund
    {
        $refund = DB::transaction(function () use ($refund, $reason, $provider): Refund {
            $locked = $this->lock($refund);

            if (! $locked->status->canTransitionTo(RefundStatus::Failed)) {
                return $locked;
            }

            if ($provider !== null) {
                $locked->provider_reference = $provider->providerReference ?? $locked->provider_reference;
                $locked->provider_status = $provider->status;
            }

            // Bounded and safe to show staff: a provider's rejection message
            // or a transport failure, never a payload or a credential.
            $locked->failure_reason = mb_substr($reason, 0, 500);
            $locked->failed_at = Carbon::now();

            return $this->apply($locked, RefundStatus::Failed);
        });

        $this->announce($refund, RefundStatus::Failed);

        return $refund;
    }

    /**
     * Re-read the refund under a row lock.
     *
     * Callers must use what comes back. Two reconciliation passes arriving
     * together would otherwise both read `Processing` and both settle it.
     */
    public function lock(Refund $refund): Refund
    {
        return Refund::whereKey($refund->getKey())->lockForUpdate()->firstOrFail();
    }

    // ------------------------------------------------------------ Internals

    /**
     * Move a paid order to `Refunded`, if everything has gone back.
     *
     * Only from `Paid` or `Processing` -- an order the platform was paid for
     * and never delivered. An order that closed as cancelled or expired keeps
     * the status it closed with, see the class comment, and the refund record
     * is what says the money was returned.
     */
    private function closeOrderIfFullyRefunded(Refund $refund): void
    {
        $order = $this->orders->lock($refund->order);

        // Paid, or being packed. Both mean the platform held the money and the
        // customer never received the goods, which is what `Refunded` says.
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing], true)) {
            return;
        }

        if (! $this->calculator->isFullyRefunded($order)) {
            return;
        }

        $this->orders->apply(
            $order,
            OrderStatus::Refunded,
            'Every pesewa taken for this order was returned to the customer.',
            null,
        );
    }

    /**
     * Write the new status, and the record of why.
     */
    private function apply(Refund $refund, RefundStatus $target): Refund
    {
        $from = $refund->status;

        $refund->status = $target;
        $refund->save();

        Log::info('Refund transitioned', [
            'operation' => 'refund.transition',
            'refund_id' => $refund->id,
            'order_id' => $refund->order_id,
            'from' => $from->value,
            'to' => $target->value,
            'provider_status' => $refund->provider_status,
            'amount_minor' => $refund->amount_minor,
        ]);

        return $refund;
    }

    /**
     * Tell the customer, once the transaction has committed.
     *
     * Never inside one: a notification written in a transaction that later
     * rolled back would describe money moving that did not move, and one that
     * threw would take the refund with it. Everything downstream is wrapped,
     * so a message that cannot be composed or sent cannot make a completed
     * refund look like a failure.
     *
     * A call that changed nothing -- a second reconciliation reaching a refund
     * somebody already settled -- announces nothing, because the status it was
     * asked to announce is not one it reached.
     */
    private function announce(Refund $refund, RefundStatus $reached): void
    {
        if ($refund->status !== $reached) {
            return;
        }

        RefundStatusChanged::dispatch($refund, $reached);
    }
}

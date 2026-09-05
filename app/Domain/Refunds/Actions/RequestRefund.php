<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Actions;

use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Refunds\Exceptions\RefundNotAllowed;
use App\Domain\Refunds\Services\RefundCalculator;
use App\Domain\Refunds\Services\RefundEligibility;
use App\Domain\Shared\Idempotency\IdempotencyGuard;
use App\Domain\Shared\Money\Money;
use App\Enums\PaymentProvider;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Puts a refund on record, before anybody talks to the provider.
 *
 * WHY THIS IS A SEPARATE STEP. If asking Paystack and recording the attempt
 * shared one transaction, a provider call that failed would roll the attempt
 * away with it, and nothing would remain to show that a member of staff tried
 * to return somebody's money. The row is committed first, `Pending`, and
 * {@see ProcessRefund} then makes the call and records whatever came back. An
 * attempt always survives its own failure.
 *
 * THE AMOUNT IS DECIDED HERE, NOT SUPPLIED. A caller may ask for a figure and
 * it is checked against what is genuinely refundable, but the default is the
 * whole refundable amount, computed from the payment's own frozen figure and
 * the refunds already against it. Nothing that reaches this action comes from
 * a browser except an order, a reason and a note.
 *
 * CONCURRENCY IS DECIDED AT THE ORDER LOCK. Two administrators pressing refund
 * at the same moment both arrive here; the first takes the order row, writes
 * its attempt and commits, and the second then blocks, reads the refundable
 * amount the first left behind, and is refused. Checking the amount without
 * the lock would let both measure themselves against the same figure and both
 * clear a cap only one of them actually cleared.
 *
 * LOCK ORDER: order, then payment. The application's established sequence --
 * order, auction, product, wallet -- with the payment taken directly after the
 * order it belongs to. Nothing here reaches an auction, a product or a wallet,
 * so no later step is skipped or reordered.
 *
 * WHAT IT NEVER DOES. It moves no credits, touches no wallet and posts no
 * inventory. Auction bid credits were consumed when the bids were accepted and
 * stay consumed; stock that has gone is gone until somebody physically returns
 * it, which is not a thing this platform does yet.
 */
final class RequestRefund
{
    public const OPERATION = 'refund.request';

    public function __construct(
        private readonly IdempotencyGuard $idempotency,
        private readonly OrderLifecycle $orders,
        private readonly RefundEligibility $eligibility,
        private readonly RefundCalculator $calculator,
    ) {}

    /**
     * Record an intention to return money against this order's payment.
     *
     * @param  Money|null  $amount  Null asks for everything still refundable,
     *                              which is the ordinary case: the platform
     *                              could deliver nothing, so it owes all of it.
     * @param  string|null  $idempotencyKey  Supplied by anything that may be
     *                                       retried. Absent, one is generated,
     *                                       and each call is a fresh request.
     *
     * @throws RefundNotAllowed
     */
    public function handle(
        Order $order,
        User $actor,
        RefundReason $reason,
        ?Money $amount = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): Refund {
        $key = $idempotencyKey ?? 'refund:'.$order->id.':'.Str::uuid()->toString();

        $result = $this->idempotency->execute(
            operation: self::OPERATION,
            key: $key,
            userId: $actor->id,
            work: fn (): array => $this->record($order, $actor, $reason, $amount, $note, $key),
        );

        return Refund::findOrFail($result['refund_id']);
    }

    /**
     * @return array{refund_id: int, order_id: int, amount_minor: int}
     */
    private function record(
        Order $order,
        User $actor,
        RefundReason $reason,
        ?Money $amount,
        ?string $note,
        string $key,
    ): array {
        return DB::transaction(function () use ($order, $actor, $reason, $amount, $note, $key): array {
            // The order first, and everything after it is read under that
            // lock. This is the step every concurrent refund on this order
            // serializes at.
            $locked = $this->orders->lock($order);

            $payment = $this->eligibility->refundablePayment($locked);

            if ($payment === null) {
                throw RefundNotAllowed::paymentNotSuccessful();
            }

            // Re-read under the lock. A stale copy would carry a refundable
            // amount computed before whoever got here first committed theirs.
            $payment = $payment->fresh();

            if ($payment === null) {
                throw RefundNotAllowed::paymentNotSuccessful();
            }

            // Null means the whole refundable amount. Computed here, inside
            // the lock, never carried in from the caller's page load.
            $requested = $amount ?? $this->calculator->refundable($payment);

            $this->eligibility->assert($locked, $payment, $requested);

            $refund = new Refund;

            $refund->order_id = $locked->id;
            $refund->order_payment_id = $payment->id;
            $refund->provider = $payment->provider;
            $refund->amount_minor = $requested->minor;
            $refund->currency = $requested->currency;
            $refund->status = RefundStatus::Pending;
            $refund->reason = $reason;
            $refund->note = $note === null ? null : mb_substr($note, 0, 500);
            $refund->requested_by = $actor->id;
            $refund->idempotency_key = $key;
            $refund->requested_at = Carbon::now();
            $refund->save();

            // Explicit, so a refund requested from a console command or a
            // queued context still names the person who asked for it rather
            // than recording nobody.
            app(CauserResolver::class)->withCauser($actor, function () use ($refund, $requested, $reason): void {
                activity('refund')
                    ->performedOn($refund)
                    ->withProperties([
                        'action' => 'requested',
                        'order_id' => $refund->order_id,
                        'order_payment_id' => $refund->order_payment_id,
                        'amount_minor' => $requested->minor,
                        'currency' => $requested->currency,
                        'reason' => $reason->value,
                    ])
                    ->log('Refund requested');
            });

            Log::info('Refund requested', [
                'operation' => 'refund.requested',
                'refund_id' => $refund->id,
                'order_id' => $locked->id,
                'order_number' => $locked->order_number,
                'order_payment_id' => $payment->id,
                'amount_minor' => $requested->minor,
                'reason' => $reason->value,
                'actor_id' => $actor->id,
            ]);

            return [
                'refund_id' => $refund->id,
                'order_id' => $locked->id,
                'amount_minor' => $requested->minor,
            ];
        });
    }

    /**
     * The provider this platform refunds through.
     *
     * Here rather than assumed at the call site, so a second provider is an
     * adapter and a column value rather than a search through the codebase.
     */
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }
}

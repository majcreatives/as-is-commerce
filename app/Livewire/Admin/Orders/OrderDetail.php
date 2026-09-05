<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Orders;

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Delivery\Services\DeliveryLifecycle;
use App\Domain\Delivery\Services\FulfilmentEligibility;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Domain\Refunds\Actions\RequestRefund;
use App\Domain\Refunds\Services\RefundCalculator;
use App\Domain\Refunds\Services\RefundEligibility;
use App\Domain\Shared\Money\Money;
use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundReason;
use App\Models\Order;
use App\Models\OrderPayment;
use DomainException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One order, in full, for staff.
 *
 * WHAT THIS SCREEN CANNOT DO, and why there is no form for any of it:
 *
 *   Mark an order paid.        There is no code path. `Paid` is reachable only
 *                              through a payment verified with the provider,
 *                              so a control here would have nothing to call.
 *   Edit an amount.            Frozen once paid, by the model and again by a
 *                              database trigger. A correction is a separate
 *                              financial act, not a rewrite of the original.
 *   Change the winning bid,
 *   the auction, or the
 *   customer.                  Same freeze, same reason.
 *   Set a refund amount
 *   freely.                    The figure is computed from the payment and the
 *                              refunds already against it. An administrator
 *                              may refund less than is owed; they cannot
 *                              refund more, and the server decides which.
 *
 * Offering a control that always failed would be worse than not offering one.
 *
 * REFUNDING IS TWO DELIBERATE STEPS. Pressing the button opens a confirmation
 * carrying the order, the customer, what was paid, what may still be returned
 * and why -- and nothing moves until that is confirmed. Returning somebody's
 * money is not a thing to do on a stray click.
 *
 * It is also two permissions, checked separately: `refunds.request` to record
 * the intention and `refunds.process` to send it to the provider. A narrower
 * finance role can hold one without the other.
 */
#[Layout('components.layouts.app')]
class OrderDetail extends Component
{
    public Order $order;

    /** Whether the refund confirmation is open. */
    public bool $confirmingRefund = false;

    public string $refundReason = '';

    public string $refundNote = '';

    // ---- Delivery inputs -------------------------------------------------
    //
    // Free text, and internal. There is no courier integration, so a carrier
    // is whatever the operator writes and a reference is ours alone -- neither
    // is verifiable anywhere outside this platform, and nothing here pretends
    // otherwise.
    public string $carrier = '';

    public string $trackingReference = '';

    public string $receivedBy = '';

    public string $deliveryNote = '';

    public string $failureReason = '';

    public function mount(Order $order): void
    {
        $this->authorize('orders.view');

        $this->order = $order;
    }

    /**
     * Move a paid order forward.
     *
     * Only to `Processing` or `Fulfilled`; the lifecycle service refuses
     * anything else, `Paid` included.
     */
    public function advance(string $target, OrderLifecycle $orders): void
    {
        $this->authorize('orders.manage');

        $status = OrderStatus::tryFrom($target);

        if ($status === null) {
            $this->addError('lifecycle', 'That is not an order state.');

            return;
        }

        try {
            $orders->advance($this->order, $status, auth()->user());
        } catch (DomainException $e) {
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        $this->order->refresh();
    }

    // -------------------------------------------------------------- Refunds

    /**
     * Open the confirmation, with the reason that fits this order pre-chosen.
     *
     * A suggestion only. Whichever reason is filed changes nothing about the
     * money -- it decides what the refund is counted as, not what it does.
     */
    public function startRefund(): void
    {
        $this->authorize('refunds.request');

        $this->refundReason = RefundReason::suggestedFor($this->order->status)->value;
        $this->refundNote = '';
        $this->resetErrorBag('refund');
        $this->confirmingRefund = true;
    }

    public function cancelRefund(): void
    {
        $this->confirmingRefund = false;
    }

    /**
     * Record the refund and send it to the provider.
     *
     * Both permissions are required, because this does both things. The
     * amount is never taken from the page: `RequestRefund` computes it under
     * the order lock from the payment's own frozen figure and whatever has
     * already been returned.
     *
     * A provider refusal is not an exception here -- the refund is recorded as
     * failed and stays in the queue -- so the only errors this catches are the
     * eligibility rules, which are worth showing the operator verbatim.
     */
    public function confirmRefund(RequestRefund $request, ProcessRefund $process): void
    {
        $this->authorize('refunds.request');
        $this->authorize('refunds.process');

        $reason = RefundReason::tryFrom($this->refundReason);

        if ($reason === null) {
            $this->addError('refund', 'Choose why this refund is being issued.');

            return;
        }

        try {
            $refund = $request->handle(
                order: $this->order,
                actor: auth()->user(),
                reason: $reason,
                note: $this->refundNote === '' ? null : $this->refundNote,
            );

            $process->handle($refund, auth()->user());
        } catch (DomainException $e) {
            $this->addError('refund', $e->getMessage());

            return;
        }

        $this->confirmingRefund = false;
        $this->order->refresh();
    }

    // ------------------------------------------------------------ Delivery

    /**
     * Move the package one step.
     *
     * A separate permission per step, because in a warehouse these are
     * different jobs done by different people: packing is not the same
     * authority as declaring an order complete, and somebody who can send a
     * package out should not necessarily be able to write off a failed one.
     *
     * The lifecycle does the guarding -- the move, the locks, the history and
     * the order coupling. This method only decides who may ask for it.
     */
    public function moveDelivery(string $target, DeliveryLifecycle $deliveries): void
    {
        $delivery = $this->order->delivery;

        if ($delivery === null) {
            $this->addError('delivery', 'This order has no delivery.');

            return;
        }

        $status = DeliveryStatus::tryFrom($target);

        if ($status === null) {
            $this->addError('delivery', 'That is not a delivery state.');

            return;
        }

        $this->authorize($this->permissionFor($status));

        try {
            match ($status) {
                DeliveryStatus::Preparing => $deliveries->prepare(
                    $delivery, auth()->user(), $this->deliveryNote ?: null,
                ),
                DeliveryStatus::ReadyForDispatch => $deliveries->markReady(
                    $delivery, auth()->user(), $this->deliveryNote ?: null,
                ),
                DeliveryStatus::Dispatched => $deliveries->dispatch(
                    $delivery,
                    auth()->user(),
                    carrier: $this->carrier ?: null,
                    reference: $this->trackingReference ?: null,
                    note: $this->deliveryNote ?: null,
                ),
                DeliveryStatus::OutForDelivery => $deliveries->markOutForDelivery(
                    $delivery, auth()->user(), $this->deliveryNote ?: null,
                ),
                DeliveryStatus::Delivered => $deliveries->markDelivered(
                    $delivery,
                    auth()->user(),
                    receivedBy: $this->receivedBy ?: null,
                    note: $this->deliveryNote ?: null,
                ),
                DeliveryStatus::Cancelled => $deliveries->cancel(
                    $delivery, auth()->user(), $this->deliveryNote ?: null,
                ),
                // Failure needs a reason and a retry needs a destination, so
                // neither is reachable from this control with an empty one.
                DeliveryStatus::DeliveryFailed, DeliveryStatus::Pending => throw DeliveryNotAllowed::because(
                    'That state is not one staff move a package to directly.'
                ),
            };
        } catch (DomainException $e) {
            $this->addError('delivery', $e->getMessage());

            return;
        }

        $this->reset('deliveryNote', 'carrier', 'trackingReference', 'receivedBy');
        $this->order->refresh();
    }

    /**
     * Record an attempt that did not work.
     *
     * Records the fact and nothing else. No refund, no restocking, no credit
     * returned, no reopened auction -- what the platform owes the customer is
     * a separate decision made through the refund workflow, by somebody
     * holding a different permission.
     */
    public function failDelivery(DeliveryLifecycle $deliveries): void
    {
        $this->authorize('deliveries.update');

        $delivery = $this->order->delivery;
        $reason = DeliveryFailureReason::tryFrom($this->failureReason);

        if ($delivery === null || $reason === null) {
            $this->addError('delivery', 'Choose why the delivery did not work.');

            return;
        }

        try {
            $deliveries->markFailed($delivery, $reason, auth()->user(), $this->deliveryNote ?: null);
        } catch (DomainException $e) {
            $this->addError('delivery', $e->getMessage());

            return;
        }

        $this->reset('deliveryNote', 'failureReason');
        $this->order->refresh();
    }

    /**
     * Put a returned package back into the flow.
     */
    public function retryDelivery(string $target, DeliveryLifecycle $deliveries): void
    {
        $this->authorize('deliveries.retry');

        $delivery = $this->order->delivery;
        $status = DeliveryStatus::tryFrom($target);

        if ($delivery === null || $status === null) {
            $this->addError('delivery', 'That is not a delivery state.');

            return;
        }

        try {
            $deliveries->retry($delivery, $status, auth()->user(), $this->deliveryNote ?: null);
        } catch (DomainException $e) {
            $this->addError('delivery', $e->getMessage());

            return;
        }

        $this->reset('deliveryNote');
        $this->order->refresh();
    }

    /**
     * Which permission each move needs.
     *
     * Stated once, so a new control cannot quietly reuse a broader permission
     * than the act deserves.
     */
    private function permissionFor(DeliveryStatus $status): string
    {
        return match ($status) {
            DeliveryStatus::Preparing, DeliveryStatus::ReadyForDispatch,
            DeliveryStatus::OutForDelivery, DeliveryStatus::Pending,
            DeliveryStatus::DeliveryFailed => 'deliveries.update',
            DeliveryStatus::Dispatched => 'deliveries.dispatch',
            DeliveryStatus::Delivered => 'deliveries.complete',
            DeliveryStatus::Cancelled => 'deliveries.cancel',
        };
    }

    public function render(
        RefundCalculator $calculator,
        RefundEligibility $eligibility,
        FulfilmentEligibility $fulfilment,
    ): View {
        $this->order->refresh();

        $payment = $eligibility->refundablePayment($this->order);

        return view('livewire.admin.orders.order-detail', [
            'pricing' => $this->order->pricing(),
            'item' => $this->order->item(),
            'payments' => $this->order->payments()->orderByDesc('id')->get(),
            'refunds' => $this->order->refunds()->with('requestedBy')->orderByDesc('id')->get(),
            'refundablePayment' => $payment,
            'refundable' => $this->refundableAmount($calculator, $payment),
            'refunded' => $this->refundedAmount($calculator, $payment),
            'canRefund' => $payment !== null
                && $eligibility->allows($this->order, $payment)
                && auth()->user()->can('refunds.request'),
            'reasons' => RefundReason::cases(),
            'delivery' => $this->order->delivery,
            'deliveryTransitions' => $this->order->delivery?->transitions()
                ->with('causedBy')->orderByDesc('id')->get(),
            'canFulfil' => $fulfilment->allows($this->order),
            'failureReasons' => DeliveryFailureReason::cases(),
        ])->title('Order '.$this->order->order_number);
    }

    private function refundableAmount(RefundCalculator $calculator, ?OrderPayment $payment): Money
    {
        return $payment === null
            ? Money::zero($this->order->currency)
            : $calculator->refundable($payment);
    }

    private function refundedAmount(RefundCalculator $calculator, ?OrderPayment $payment): Money
    {
        return $payment === null
            ? Money::zero($this->order->currency)
            : $calculator->refunded($payment);
    }
}

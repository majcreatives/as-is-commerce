<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Services;

use App\Domain\Refunds\Exceptions\RefundNotAllowed;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderPayment;

/**
 * Whether a refund may happen at all.
 *
 * ONE PLACE, CHECKED ON THE SERVER, EVERY TIME. A hidden button is not a rule
 * -- it is a rule nobody enforced. The admin screen asks this to decide what
 * to show, and the action asks it again under the order lock before writing
 * anything, because the answer can change between a page loading and a button
 * being pressed.
 *
 * WHAT IS ELIGIBLE, AND WHY IT IS THIS NARROW.
 *
 * A refund exists here to return money the platform took and could not deliver
 * against. That is exactly the situation Stages 7 and 8 recorded and
 * deliberately left open: a verified payment, an order carrying a
 * `fulfilment_blocked_reason`, and a person left to decide what was owed.
 * Stage 10 is that decision being made.
 *
 * So a refund needs all of:
 *
 *   - a payment that actually succeeded
 *   - something still refundable against it
 *   - no refund already in flight for it
 *   - an order whose fulfilment is blocked
 *   - an order that was never delivered
 *
 * WHAT IS DELIBERATELY NOT ELIGIBLE:
 *
 *   A healthy paid order.   Nothing went wrong with it. The customer is
 *                           getting their item, and refunding while the sale
 *                           stands would give away both the money and the
 *                           stock -- a refund restores no inventory.
 *   A delivered order.      That is a return: goods come back, and reverse
 *                           logistics do not exist here yet.
 *   An unpaid order.        There is no money to return. A cancelled checkout
 *                           that was never paid is simply closed.
 *
 * Widening any of these is a business decision with an inventory consequence,
 * and is not something to slip in behind a button.
 */
class RefundEligibility
{
    public function __construct(
        private readonly RefundCalculator $calculator,
    ) {}

    /**
     * Throw unless this payment may be refunded for this amount.
     *
     * Callers must already hold the order row lock. Every check that follows
     * reads state two administrators could otherwise race each other on.
     *
     * @throws RefundNotAllowed
     */
    public function assert(Order $order, OrderPayment $payment, Money $amount): void
    {
        $this->assertOrderIsRecoverable($order);

        if (! $payment->isSuccessful()) {
            throw RefundNotAllowed::paymentNotSuccessful();
        }

        // Order matters here, and it is the specific explanation first. An
        // in-flight refund holds back the whole refundable amount, so a
        // caller asking for "whatever is left" arrives with zero -- and being
        // told an amount must be positive would send an operator looking for
        // a problem with the figure rather than at the refund already running.
        if ($this->hasRefundInFlight($payment)) {
            throw RefundNotAllowed::alreadyInProgress();
        }

        $refundable = $this->calculator->refundable($payment);

        if ($refundable->isZero()) {
            throw RefundNotAllowed::nothingRefundable();
        }

        if (! $amount->isPositive()) {
            throw RefundNotAllowed::notPositive();
        }

        if ($amount->minor > $refundable->minor) {
            throw RefundNotAllowed::exceedsRefundable($amount, $refundable);
        }
    }

    /**
     * Whether a refund could be started, without saying why not.
     *
     * For deciding what to render. The action asks {@see self::assert()}
     * again, under the lock, and that is what actually governs.
     */
    public function allows(Order $order, OrderPayment $payment): bool
    {
        try {
            $this->assert($order, $payment, $this->calculator->refundable($payment));
        } catch (RefundNotAllowed) {
            return false;
        }

        return true;
    }

    /**
     * The payment a refund on this order would be against.
     *
     * At most one attempt per order ever reaches success, so this is
     * unambiguous. A refund names the attempt rather than the order, because
     * the attempt is what the provider settled and what its amount is frozen
     * against.
     */
    public function refundablePayment(Order $order): ?OrderPayment
    {
        return $order->payments()->successful()->first();
    }

    /**
     * Whether an attempt against this payment is still capable of succeeding.
     *
     * One at a time, deliberately. Two concurrent attempts would each have to
     * reserve part of the refundable amount to be safe, and a second attempt
     * while the first is undecided is nearly always somebody pressing a button
     * twice rather than an intention to split a refund.
     */
    public function hasRefundInFlight(OrderPayment $payment): bool
    {
        return $payment->refunds()->inFlight()->exists();
    }

    /**
     * @throws RefundNotAllowed
     */
    private function assertOrderIsRecoverable(Order $order): void
    {
        // Only `Fulfilled` means the customer has the goods. `Processing`
        // means staff are packing -- since deliveries exist, that is a
        // concrete state with a package still in the building -- so a blocked
        // order there is a legitimate recovery case, and the blocked check
        // below is what governs it. Refusing it here would tell an operator a
        // package had been delivered when it is on their own shelf.
        if ($order->status === OrderStatus::Fulfilled) {
            throw RefundNotAllowed::alreadyDelivered();
        }

        if (! $order->isFulfilmentBlocked()) {
            throw RefundNotAllowed::orderNotRecoverable($order->status->value);
        }
    }
}

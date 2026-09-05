<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Whether an order may enter physical fulfilment at all.
 *
 * ONE PLACE, ASKED EVERY TIME. Delivery creation asks it, and every transition
 * that moves a package asks it again -- because an order can stop being
 * fulfillable after its delivery exists. A refund approved while a box sits in
 * the warehouse must stop that box going out, and the only way that happens is
 * if the question is asked again rather than answered once at creation.
 *
 * WHAT IS REQUIRED, and each of these is a way the platform could otherwise
 * ship something it should not:
 *
 *   Paid, and still paid       The one and only source of truth about money.
 *                              A refunded order has had its money returned and
 *                              must not also receive the goods.
 *   Not cancelled or expired   Those orders closed. Nothing ships against a
 *                              checkout that was never completed.
 *   Not fulfilment-blocked     A payment succeeded and nothing could be
 *                              delivered against it -- often because another
 *                              transaction took the unit. Shipping anyway
 *                              would mean sending stock that belongs to
 *                              somebody else's order.
 *   Has something to send      An order with no line has no package.
 *
 * IT ASKS ABOUT THE ORDER, NEVER ABOUT THE PAYMENT DIRECTLY. `OrderStatus`
 * already encodes what a verified payment established, and re-deriving it here
 * would be a second opinion on a question that has one authoritative answer.
 */
class FulfilmentEligibility
{
    /**
     * Throw unless this order may be fulfilled.
     *
     * Callers moving a package must already hold the order row lock: the
     * status this reads is exactly what a concurrent refund would change.
     *
     * @throws DeliveryNotAllowed
     */
    public function assert(Order $order): void
    {
        if ($order->isFulfilmentBlocked()) {
            throw DeliveryNotAllowed::orderBlocked();
        }

        if (! $this->isFulfillableStatus($order->status)) {
            throw DeliveryNotAllowed::orderNotFulfillable($order->status->value);
        }

        if ($order->item() === null) {
            throw DeliveryNotAllowed::because('This order has nothing to deliver.');
        }
    }

    /**
     * Whether this order may be fulfilled, without saying why not.
     *
     * For deciding what to render. Every path that actually moves a package
     * calls {@see self::assert()} under a lock, and that is what governs.
     */
    public function allows(Order $order): bool
    {
        try {
            $this->assert($order);
        } catch (DeliveryNotAllowed) {
            return false;
        }

        return true;
    }

    /**
     * The order states from which a physical package may still move.
     *
     * `Paid` is where fulfilment begins and `Processing` is where it continues.
     * `Fulfilled` is absent deliberately: the customer already has the item,
     * and a further transition would be describing a second delivery of the
     * same thing.
     */
    private function isFulfillableStatus(OrderStatus $status): bool
    {
        return match ($status) {
            OrderStatus::Paid, OrderStatus::Processing => true,
            OrderStatus::PendingPayment, OrderStatus::Fulfilled, OrderStatus::Cancelled,
            OrderStatus::PaymentFailed, OrderStatus::PaymentExpired, OrderStatus::Refunded => false,
        };
    }
}

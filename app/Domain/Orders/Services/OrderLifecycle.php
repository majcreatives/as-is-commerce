<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Exceptions\InvalidOrderTransition;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderTransition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The order state machine, and the stock that follows it.
 *
 * Every move is checked against {@see OrderStatus::allowedTransitions()},
 * recorded in `order_transitions`, and applied in one transaction with
 * whatever else it implies. Nothing else in the application writes
 * `orders.status`.
 *
 * THERE IS NO METHOD HERE THAT MARKS AN ORDER PAID. That is deliberate and is
 * the point of the whole design: the only way into `Paid` runs through
 * {@see FulfillOrderPayment}, which first asks the provider server-to-server
 * what actually happened. An administrative control, a callback parameter or a
 * browser cannot reach it, because no such path exists to be misused.
 *
 * `Processing` and `Fulfilled` are ordinary operational work -- the money is
 * in and the platform is getting the item to the customer -- and both are
 * audited like everything else.
 *
 * RESERVATIONS ARE RELEASED WHERE THEY WERE TAKEN. A Buy Now checkout on a
 * product with no auction holds one unit aside so it cannot be sold from under
 * a customer who is paying for it; cancelling or expiring gives it back. An
 * auction-linked order holds nothing, because the auction already reserved the
 * unit and a second reservation would take two units off the shelf for one
 * sale. The `holds_reservation` column says which, explicitly, rather than
 * being inferred -- releasing a reservation nobody took would overstate
 * available stock.
 */
class OrderLifecycle
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Withdraw an unpaid checkout.
     *
     * Releases the held unit, if this order was holding one.
     */
    public function cancel(Order $order, string $reason, ?User $actor = null): Order
    {
        return DB::transaction(function () use ($order, $reason, $actor): Order {
            $locked = $this->lock($order);

            $this->assertCanMove($locked, OrderStatus::Cancelled);

            $this->releaseReservation($locked, $actor, 'Checkout cancelled.');

            $locked->cancelled_at = Carbon::now();

            return $this->apply($locked, OrderStatus::Cancelled, $reason, $actor);
        });
    }

    /**
     * The checkout window closed before payment arrived.
     *
     * This is what stops an abandoned checkout holding stock indefinitely. It
     * is driven by the clock rather than by anybody's decision, which is why
     * it is a separate state from Cancelled.
     */
    public function expire(Order $order, ?Carbon $now = null): Order
    {
        return DB::transaction(function () use ($order, $now): Order {
            $locked = $this->lock($order);

            // Somebody paid, or cancelled, between the sweep selecting this
            // row and reaching it. Both are ordinary.
            if (! $locked->status->acceptsPayment()) {
                return $locked;
            }

            if (! $locked->hasExpired($now)) {
                return $locked;
            }

            $this->releaseReservation($locked, null, 'Checkout expired unpaid.');

            return $this->apply(
                $locked,
                OrderStatus::PaymentExpired,
                'The checkout window closed before a payment was verified.',
                null,
            );
        });
    }

    /**
     * The provider reported the payment did not succeed.
     */
    public function markPaymentFailed(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason): Order {
            $locked = $this->lock($order);

            if (! $locked->status->acceptsPayment()) {
                return $locked;
            }

            $this->releaseReservation($locked, null, 'Payment failed.');

            return $this->apply($locked, OrderStatus::PaymentFailed, $reason, null);
        });
    }

    /**
     * Move a paid order along its operational path.
     *
     * Only forwards, and only between the states that describe getting a paid
     * item to a customer. This cannot reach Paid from anywhere: an
     * administrator has no way to assert that money arrived.
     */
    public function advance(Order $order, OrderStatus $target, ?User $actor = null): Order
    {
        if (! in_array($target, [OrderStatus::Processing, OrderStatus::Fulfilled], true)) {
            throw InvalidOrderTransition::because(
                "[{$target->value}] is not an operational state an order can be advanced to. "
                .'An order becomes paid only through a verified payment.'
            );
        }

        return DB::transaction(function () use ($order, $target, $actor): Order {
            $locked = $this->lock($order);

            $this->assertCanMove($locked, $target);

            if ($target === OrderStatus::Fulfilled) {
                $locked->fulfilled_at = Carbon::now();
            }

            return $this->apply(
                $locked,
                $target,
                $target === OrderStatus::Fulfilled
                    ? 'Delivered to the customer.'
                    : 'Being prepared for the customer.',
                $actor,
            );
        });
    }

    /**
     * Record that a paid order could not be completed.
     *
     * The money is real and stays recorded. This says why nothing further
     * happened, so the order lands in a queue for a human rather than being
     * silently marked done or silently lost. What is owed to the customer is a
     * decision for a person, and refunds are not built in this stage.
     */
    public function blockFulfilment(Order $order, string $reason): Order
    {
        $order->fulfilment_blocked_reason = mb_substr($reason, 0, 500);
        $order->save();

        Log::warning('Paid order could not be completed', [
            'operation' => 'order.fulfilment_blocked',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'reason' => $reason,
        ]);

        return $order;
    }

    // ------------------------------------------------------------ Inventory

    /**
     * Hold one unit aside for this checkout.
     *
     * Only for a purchase with no auction behind it. An auction-linked order
     * holds nothing: the auction reserved the unit when it was published, and
     * that same unit is the one being bought.
     */
    public function reserveUnit(Order $order, ?User $actor = null): void
    {
        $item = $order->item();

        if ($item === null) {
            throw InvalidOrderTransition::because('An order with no items cannot reserve stock.');
        }

        $this->inventory->reserve(
            product: $item->product,
            quantity: $item->quantity,
            reference: $order,
            reason: "Held for checkout {$order->order_number}.",
            actor: $actor,
        );

        $order->holds_reservation = true;
        $order->save();
    }

    /**
     * Give back the unit this order was holding, if it was holding one.
     *
     * Checked rather than assumed, and the flag is cleared in the same write,
     * so a second call cannot release a unit twice.
     */
    public function releaseReservation(Order $order, ?User $actor, string $reason): void
    {
        if (! $order->holds_reservation) {
            return;
        }

        $item = $order->item();

        if ($item === null) {
            return;
        }

        $this->inventory->release(
            product: $item->product,
            quantity: $item->quantity,
            reference: $order,
            reason: $reason,
            actor: $actor,
        );

        $order->holds_reservation = false;
        $order->save();
    }

    /**
     * Turn this order's own reservation into a sale.
     *
     * Used by the Buy Now path when no auction is involved. Auction-linked
     * orders are completed through the auction, which sells the unit it was
     * holding -- one sale either way, and never two.
     */
    public function sellUnit(Order $order, ?User $actor = null): void
    {
        $item = $order->item();

        if ($item === null) {
            throw InvalidOrderTransition::because('An order with no items cannot record a sale.');
        }

        $this->inventory->recordSale(
            product: $item->product,
            quantity: $item->quantity,
            reference: $order,
            reason: "Sold on order {$order->order_number}.",
            actor: $actor,
        );

        // The reservation has become a sale. The inventory service nets the
        // two, so the flag must be cleared or a later release would give back
        // a unit that has already left.
        $order->holds_reservation = false;
        $order->save();
    }

    // ------------------------------------------------------------ Internals

    /**
     * Re-read the order under a row lock.
     *
     * Callers must use the returned instance: the one passed in may be stale,
     * and a decision made on a stale status is how an order gets paid twice.
     */
    public function lock(Order $order): Order
    {
        return Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws InvalidOrderTransition
     */
    public function assertCanMove(Order $order, OrderStatus $target): void
    {
        if (! $order->status->canTransitionTo($target)) {
            throw InvalidOrderTransition::between($order->status, $target);
        }
    }

    /**
     * Write the new status and the record of the change.
     *
     * Public so fulfilment, which has more to do in the same transaction, can
     * reuse it. Callers are responsible for holding the order row lock first.
     */
    public function apply(
        Order $order,
        OrderStatus $target,
        ?string $reason,
        ?User $actor,
    ): Order {
        $this->assertCanMove($order, $target);

        $from = $order->status;

        $order->status = $target;
        $order->save();

        OrderTransition::create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $target,
            'reason' => $reason,
            'caused_by' => $actor?->id,
        ]);

        Log::info('Order transitioned', [
            'operation' => 'order.transition',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'from' => $from->value,
            'to' => $target->value,
            'reason' => $reason,
            'actor_id' => $actor?->id,
        ]);

        return $order;
    }
}

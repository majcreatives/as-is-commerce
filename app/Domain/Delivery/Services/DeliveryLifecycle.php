<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Events\DeliveryStatusChanged;
use App\Models\Delivery;
use App\Models\DeliveryTransition;
use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The delivery state machine, and the order state that follows it.
 *
 * Nothing else in the application writes `deliveries.status`. Every move is
 * checked against {@see DeliveryStatus::allowedTransitions()}, recorded in
 * `delivery_transitions` with an actor and a time, and applied in one
 * transaction with whatever it implies for the order.
 *
 * MANUAL DOES NOT MEAN UNGOVERNED. Every transition here is somebody in a
 * warehouse saying what they just did. That is precisely why it is guarded: a
 * manual process has no courier API to ask afterwards what really happened, so
 * the record this service writes is the only account there will ever be.
 *
 * THERE IS NO METHOD HERE THAT TOUCHES MONEY. Not a payment, not a refund, not
 * a credit, not a wallet, not an inventory movement, not an auction result.
 * A package moving is not a statement about money, and the class is arranged so
 * that a member of staff cannot accidentally make one. The only thing it moves
 * outside its own domain is the order's status, through the order's own
 * lifecycle, which applies its own guards.
 *
 * TWO PLACES THE ORDER MOVES, AND ONLY TWO:
 *
 *   Preparing  → order Paid becomes Processing. Somebody has started work.
 *   Delivered  → order becomes Fulfilled. The customer has the item.
 *
 * Everything between leaves the order alone, because commercially nothing has
 * changed: the platform was paid and is getting the item to the customer,
 * whether the box is on a shelf or in a van.
 *
 * LOCK ORDER: the order row, then the delivery row. The application's
 * established sequence with delivery appended at the end, after everything
 * financial. Nothing here reaches an auction, a product or a wallet.
 *
 * IDEMPOTENT BY CONSTRUCTION. A move to the status a delivery is already at
 * does nothing at all: no history row, no order change, no notification. Two
 * members of staff marking the same package delivered produce one delivery,
 * one order transition and one message.
 */
class DeliveryLifecycle
{
    public function __construct(
        private readonly OrderLifecycle $orders,
        private readonly FulfilmentEligibility $eligibility,
    ) {}

    /**
     * Work has begun on the package.
     *
     * Moves the order to `Processing` alongside, which is the one place the
     * two lifecycles legitimately move together: staff picking stock is the
     * commercial meaning of an order being processed.
     */
    public function prepare(Delivery $delivery, ?User $actor = null, ?string $note = null): Delivery
    {
        return $this->move(
            $delivery,
            DeliveryStatus::Preparing,
            $actor,
            note: $note,
            apply: function (Delivery $locked): void {
                // Kept from the first time, so a retry after a failed delivery
                // does not rewrite when preparation originally began.
                $locked->prepared_at ??= Carbon::now();
            },
            advanceOrderTo: OrderStatus::Processing,
        );
    }

    /**
     * Packed, labelled and waiting to go out.
     */
    public function markReady(Delivery $delivery, ?User $actor = null, ?string $note = null): Delivery
    {
        return $this->move(
            $delivery,
            DeliveryStatus::ReadyForDispatch,
            $actor,
            note: $note,
            apply: function (Delivery $locked): void {
                $locked->ready_at = Carbon::now();
            },
            advanceOrderTo: OrderStatus::Processing,
        );
    }

    /**
     * Handed to whoever is taking it.
     *
     * The carrier and reference are free text and internal. There is no
     * courier integration, so nothing here is externally verifiable and
     * nothing pretends to be.
     */
    public function dispatch(
        Delivery $delivery,
        ?User $actor = null,
        ?string $carrier = null,
        ?string $reference = null,
        ?string $note = null,
    ): Delivery {
        return $this->move(
            $delivery,
            DeliveryStatus::Dispatched,
            $actor,
            note: $note,
            apply: function (Delivery $locked) use ($carrier, $reference): void {
                $locked->dispatched_at = Carbon::now();
                $locked->carrier = $carrier === null || $carrier === '' ? $locked->carrier : mb_substr($carrier, 0, 120);
                $locked->tracking_reference = $reference === null || $reference === ''
                    ? $locked->tracking_reference
                    : mb_substr($reference, 0, 100);
                // A new attempt begins. Counted for operations to see; nothing
                // in the application decides anything from the number, and
                // there is deliberately no maximum.
                $locked->attempts++;
            },
        );
    }

    /**
     * On its way to the customer right now.
     *
     * Optional. A rider who takes a package out and hands it over within the
     * hour may go straight from dispatched to delivered, and requiring this
     * step would only produce invented timestamps.
     */
    public function markOutForDelivery(Delivery $delivery, ?User $actor = null, ?string $note = null): Delivery
    {
        return $this->move(
            $delivery,
            DeliveryStatus::OutForDelivery,
            $actor,
            note: $note,
            apply: function (Delivery $locked): void {
                $locked->out_for_delivery_at = Carbon::now();
            },
        );
    }

    /**
     * The customer has it, and the order is complete.
     *
     * The one transition that finishes an order. It goes through the order's
     * own lifecycle rather than writing a status here, so every guard that
     * governs an order reaching `Fulfilled` still applies -- a delivery cannot
     * push an order somewhere the order refuses to go.
     *
     * Proof of delivery is who took it and what the person handing it over
     * wrote down. That is what a manual process can honestly record; a
     * signature, a photograph or a location fix would each need a capability
     * this platform does not have.
     */
    public function markDelivered(
        Delivery $delivery,
        ?User $actor = null,
        ?string $receivedBy = null,
        ?string $note = null,
    ): Delivery {
        return $this->move(
            $delivery,
            DeliveryStatus::Delivered,
            $actor,
            note: $note,
            apply: function (Delivery $locked) use ($receivedBy, $note): void {
                $locked->delivered_at = Carbon::now();
                $locked->received_by = $receivedBy === null || $receivedBy === ''
                    ? $locked->recipient_name
                    : mb_substr($receivedBy, 0, 120);
                $locked->delivery_note = $note === null ? null : mb_substr($note, 0, 500);
                // Cleared: this package arrived, and leaving an old failure on
                // it would make the record read as though it had not.
                $locked->failure_reason = null;
                $locked->failure_note = null;
            },
            advanceOrderTo: OrderStatus::Fulfilled,
        );
    }

    /**
     * An attempt was made and did not succeed.
     *
     * WHAT THIS DOES NOT DO, and none of it is an oversight: it does not
     * refund, restore stock, return a credit, cancel a payment, reopen an
     * auction or reverse anything. A failed delivery is an operational
     * exception -- the platform still has the customer's money and still has
     * the item, and what to do about that is a person's decision made through
     * the refund workflow, not a consequence inferred from a rider's report.
     *
     * The order is deliberately left where it is. It is still paid, and the
     * platform still owes the customer either the item or their money.
     */
    public function markFailed(
        Delivery $delivery,
        DeliveryFailureReason $reason,
        ?User $actor = null,
        ?string $note = null,
    ): Delivery {
        return $this->move(
            $delivery,
            DeliveryStatus::DeliveryFailed,
            $actor,
            note: $note,
            reason: $reason,
            apply: function (Delivery $locked) use ($reason, $note): void {
                $locked->failed_at = Carbon::now();
                $locked->failure_reason = $reason;
                $locked->failure_note = $note === null ? null : mb_substr($note, 0, 500);
            },
            // No eligibility check. Recording what actually happened must
            // always be possible, including on an order that has since been
            // refunded -- a package that failed still failed.
            requiresEligibleOrder: false,
        );
    }

    /**
     * Try again with a package that came back.
     *
     * Not automatic, and deliberately so. A delivery that failed failed for a
     * reason, and something -- a corrected phone number, a different day, a
     * conversation with the customer -- has to change before another attempt
     * is worth making. A timer would simply repeat the failure.
     *
     * Returns the package to a state that means it is in hand. Which one is
     * the operator's call: a package that came back intact is ready to go out
     * again, one that needs repacking is not.
     */
    public function retry(
        Delivery $delivery,
        DeliveryStatus $target = DeliveryStatus::Preparing,
        ?User $actor = null,
        ?string $note = null,
    ): Delivery {
        if (! in_array($target, [DeliveryStatus::Preparing, DeliveryStatus::ReadyForDispatch], true)) {
            throw DeliveryNotAllowed::because(
                'A retry returns a package to preparing or ready for dispatch. '
                .'Those are the states that mean it is back in our hands.'
            );
        }

        return $this->move(
            $delivery,
            $target,
            $actor,
            note: $note ?? 'Retrying after a failed delivery.',
            apply: function (Delivery $locked) use ($target): void {
                if ($target === DeliveryStatus::ReadyForDispatch) {
                    $locked->ready_at = Carbon::now();
                }

                // The failure stays on the transition history, which is where
                // it belongs. Clearing it from the delivery keeps the current
                // state describing the present attempt rather than the last
                // one, and the history keeps the count honest.
                $locked->failure_reason = null;
                $locked->failure_note = null;
            },
            advanceOrderTo: OrderStatus::Processing,
        );
    }

    /**
     * Stop a package that has not gone out.
     *
     * An operational act, not a financial one. It refunds nothing, restores no
     * stock, returns no credit and reopens no auction. If the customer is owed
     * money, that goes through the refund workflow with its own record and its
     * own authorization.
     */
    public function cancel(Delivery $delivery, ?User $actor = null, ?string $note = null): Delivery
    {
        return $this->move(
            $delivery,
            DeliveryStatus::Cancelled,
            $actor,
            note: $note,
            apply: function (Delivery $locked): void {
                $locked->cancelled_at = Carbon::now();
            },
            // Cancelling is how an operator responds to an order that has
            // stopped being fulfillable -- a refund, most often -- so it must
            // not itself require the order to be fulfillable.
            requiresEligibleOrder: false,
        );
    }

    // ------------------------------------------------------------ Internals

    /**
     * Every transition, in one place.
     *
     * @param  Closure(Delivery): void|null  $apply  Fields this move sets.
     * @param  OrderStatus|null  $advanceOrderTo  Where the order should move,
     *                                            if it is not already there or
     *                                            further along.
     */
    private function move(
        Delivery $delivery,
        DeliveryStatus $target,
        ?User $actor,
        ?string $note = null,
        ?DeliveryFailureReason $reason = null,
        ?Closure $apply = null,
        ?OrderStatus $advanceOrderTo = null,
        bool $requiresEligibleOrder = true,
    ): Delivery {
        [$delivery, $moved] = DB::transaction(function () use (
            $delivery, $target, $actor, $note, $reason, $apply, $advanceOrderTo, $requiresEligibleOrder
        ): array {
            // The order first, then the delivery. Every concurrent move on
            // this package serializes here, and the order status the
            // eligibility check reads is the one nobody else can change until
            // this commits.
            $order = $this->orders->lock($delivery->order);
            $locked = $this->lock($delivery);

            // Already there. Not an error -- two members of staff pressing the
            // same button, or a browser retrying -- and deliberately silent:
            // no history row, no order change, no notification.
            if ($locked->status === $target) {
                return [$locked, false];
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw DeliveryNotAllowed::between($locked->status, $target);
            }

            if ($requiresEligibleOrder) {
                // Asked again, not just at creation. An order refunded while a
                // box sat in the warehouse must stop that box going out, and
                // this is the only place that happens.
                $this->eligibility->assert($order);
            }

            // Nothing leaves `pending` without somewhere to go. A database
            // CHECK constraint refuses it too; this is so an operator gets an
            // explanation rather than a constraint violation.
            if ($target !== DeliveryStatus::Cancelled && ! $locked->hasAddress()) {
                throw DeliveryNotAllowed::noAddress();
            }

            $from = $locked->status;

            if ($apply !== null) {
                $apply($locked);
            }

            $locked->status = $target;
            $locked->save();

            DeliveryTransition::create([
                'delivery_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $target,
                'reason_code' => $reason,
                'note' => $note === null ? null : mb_substr($note, 0, 500),
                'caused_by' => $actor?->id,
            ]);

            if ($advanceOrderTo !== null) {
                $this->advanceOrder($order, $advanceOrderTo, $actor);
            }

            Log::info('Delivery transitioned', [
                'operation' => 'delivery.transition',
                'delivery_id' => $locked->id,
                'reference' => $locked->reference,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'from' => $from->value,
                'to' => $target->value,
                'reason' => $reason?->value,
                'actor_id' => $actor?->id,
            ]);

            return [$locked, true];
        });

        if ($moved) {
            $this->announce($delivery, $target);
        }

        return $delivery;
    }

    /**
     * Move the order along, if the move is one it has not already made.
     *
     * Silently skipped when the order is already at or past the target: a
     * package going from preparing to ready does not need to assert
     * `Processing` twice, and an order somebody advanced by hand should not
     * make a delivery transition fail.
     */
    private function advanceOrder(Order $order, OrderStatus $target, ?User $actor): void
    {
        if ($order->status === $target) {
            return;
        }

        if (! $order->status->canTransitionTo($target)) {
            // Already further along, or somewhere this move does not apply to.
            // The delivery's own guards have already established that the
            // package may move; the order simply has nothing to do.
            return;
        }

        if ($target === OrderStatus::Fulfilled) {
            $order->fulfilled_at = Carbon::now();
        }

        $this->orders->apply(
            $order,
            $target,
            $target === OrderStatus::Fulfilled
                ? 'The customer received the delivery.'
                : 'Staff began preparing this order for delivery.',
            $actor,
        );
    }

    /**
     * Re-read the delivery under a row lock.
     *
     * Callers must use what comes back. Two members of staff marking the same
     * package delivered would otherwise both read the earlier status and both
     * write a transition.
     */
    public function lock(Delivery $delivery): Delivery
    {
        return Delivery::whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Tell the customer, once the transaction has committed.
     *
     * Never inside one: a message written in a transaction that later rolled
     * back would tell somebody their order was delivered when it was not, and
     * one that threw would take the delivery with it. Everything downstream is
     * wrapped, so a message that cannot be composed or sent cannot make a
     * completed handover look like a failure.
     */
    private function announce(Delivery $delivery, DeliveryStatus $reached): void
    {
        if ($delivery->status !== $reached) {
            return;
        }

        DeliveryStatusChanged::dispatch($delivery, $reached);
    }
}

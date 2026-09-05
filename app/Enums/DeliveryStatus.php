<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a physical package has got to.
 *
 * ```
 * Pending → Preparing → ReadyForDispatch → Dispatched → OutForDelivery → Delivered
 *                                              ↓              ↓
 *                                         DeliveryFailed ─────┘
 *                                              ↓
 *                                      Preparing (retry)
 * ```
 *
 * THIS IS NOT THE ORDER'S STATE, AND NOT THE PAYMENT'S. The separation is
 * deliberate and load-bearing. An order sits at `Processing` for the whole of
 * `Preparing`, `ReadyForDispatch`, `Dispatched` and `OutForDelivery`, because
 * commercially nothing has changed -- the platform was paid and is getting the
 * item to the customer. What changes is where the box is.
 *
 * Collapsing the two would mean a member of staff moving a package appearing
 * to make a statement about money, which is exactly the confusion the whole
 * design is arranged to prevent.
 *
 * DELIVERY IS MANUAL, AND THAT DOES NOT MEAN UNGOVERNED. Every move here is
 * somebody in a warehouse saying what they just did, but it is still checked
 * against {@see self::allowedTransitions()}, authorized, recorded with an
 * actor and a time, and applied under a lock. A manual process needs its
 * history more than an automated one does, not less: there is no courier API
 * to ask afterwards what happened.
 *
 * `OutForDelivery` is optional. A rider who takes a package out and hands it
 * over in the same hour may go straight from `Dispatched` to `Delivered`, and
 * forcing an intermediate click would only produce inaccurate timestamps.
 */
enum DeliveryStatus: string
{
    /** Created with the order. Nobody has touched the package yet. */
    case Pending = 'pending';

    /** Being picked and packed. */
    case Preparing = 'preparing';

    /** Packed, labelled, waiting to go out. */
    case ReadyForDispatch = 'ready_for_dispatch';

    /** Handed to whoever is taking it. */
    case Dispatched = 'dispatched';

    /** On its way to the customer right now. */
    case OutForDelivery = 'out_for_delivery';

    /** The customer has it. Terminal. */
    case Delivered = 'delivered';

    /** An attempt was made and did not succeed. Recoverable. */
    case DeliveryFailed = 'delivery_failed';

    /** Stopped before it went out. Terminal. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Preparing => 'Preparing',
            self::ReadyForDispatch => 'Ready for dispatch',
            self::Dispatched => 'Dispatched',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::DeliveryFailed => 'Delivery failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * What the customer is told this means.
     *
     * Plainer than the internal label, and never speculative. "Ready for
     * dispatch" is warehouse vocabulary; "packed and waiting to go out" is
     * what actually happened.
     */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Pending => 'Order confirmed',
            self::Preparing => 'Preparing your order',
            self::ReadyForDispatch => 'Packed and ready to go',
            self::Dispatched => 'On its way',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::DeliveryFailed => 'Delivery attempt unsuccessful',
            self::Cancelled => 'Delivery cancelled',
        };
    }

    /**
     * Whether the package has left the building.
     *
     * The line cancellation stops at: something already out with a rider is
     * not cancelled, it either arrives or fails.
     */
    public function hasLeft(): bool
    {
        return match ($this) {
            self::Dispatched, self::OutForDelivery, self::Delivered => true,
            self::Pending, self::Preparing, self::ReadyForDispatch,
            self::DeliveryFailed, self::Cancelled => false,
        };
    }

    /**
     * Whether staff still have work to do on this delivery.
     *
     * The fulfilment queue's definition of outstanding.
     */
    public function isOutstanding(): bool
    {
        return ! $this->isTerminal();
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The only legal moves.
     *
     * Note what is missing. Nothing goes backwards along the happy path -- a
     * package that has been dispatched cannot become "preparing" again,
     * because it is not in the building. Nothing leaves `Delivered`: the
     * customer has the item, and a later problem is a return, which this
     * platform does not yet do.
     *
     * `DeliveryFailed` is the one state that reopens, and only to the two
     * states that describe having the package back in hand. That is the retry
     * path, and it is deliberately a decision somebody makes rather than
     * something that happens on a timer.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Preparing, self::Cancelled],
            self::Preparing => [self::ReadyForDispatch, self::Cancelled],
            self::ReadyForDispatch => [self::Dispatched, self::Cancelled],
            // Straight to Delivered is allowed: OutForDelivery is an optional
            // step, and requiring it would only produce invented timestamps.
            self::Dispatched => [self::OutForDelivery, self::Delivered, self::DeliveryFailed],
            self::OutForDelivery => [self::Delivered, self::DeliveryFailed],
            // Terminal. The customer has it.
            self::Delivered => [],
            // Recoverable: back to the states that mean the package is in hand.
            self::DeliveryFailed => [self::Preparing, self::ReadyForDispatch, self::Cancelled],
            // Terminal. Restarting is a new delivery decision, not a status
            // change on the one that was stopped.
            self::Cancelled => [],
        };
    }

    /**
     * The states a customer's tracking timeline shows, in order.
     *
     * Failure and cancellation are deliberately absent: they are not steps
     * along the way, and rendering them as part of a progress line would
     * suggest every delivery passes through them.
     *
     * @return list<self>
     */
    public static function trackingSteps(): array
    {
        return [
            self::Pending,
            self::Preparing,
            self::ReadyForDispatch,
            self::Dispatched,
            self::OutForDelivery,
            self::Delivered,
        ];
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Delivered => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Dispatched, self::OutForDelivery => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::ReadyForDispatch => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::DeliveryFailed => 'bg-red-50 text-red-800 ring-red-200',
            self::Pending, self::Preparing, self::Cancelled => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an order sits in its own lifecycle.
 *
 * ```
 * PendingPayment → Paid → Processing → Fulfilled
 * ```
 *
 * THIS IS NOT THE AUCTION'S STATE, AND NOT THE PAYMENT'S. The separation is
 * deliberate and load-bearing: an auction may be `PendingSettlement` while its
 * winner's order is `PendingPayment`, and after a successful payment the
 * auction becomes `Settled` while the order becomes `Paid`. Each domain owns
 * its own lifecycle, and collapsing them would mean a change in one silently
 * asserting something about the other.
 *
 * ONLY A VERIFIED PAYMENT REACHES `Paid`. Not a browser returning from the
 * provider, not a callback query parameter, not an administrator's judgement.
 * The one path into this state runs through server-to-server verification, so
 * the state means what it says.
 *
 * `Processing` and `Fulfilled` are operational: the money is in and the
 * platform is getting the item to the customer. Advancing them is legitimate
 * administrative work and is audited; there is no control anywhere that marks
 * an order paid by hand.
 */
enum OrderStatus: string
{
    /** Created, priced and frozen. Awaiting a verified payment. */
    case PendingPayment = 'pending_payment';

    /** A payment was verified with the provider. Money is in. */
    case Paid = 'paid';

    /** Being prepared for the customer. */
    case Processing = 'processing';

    /** The customer has it. Terminal. */
    case Fulfilled = 'fulfilled';

    /** Withdrawn before payment. */
    case Cancelled = 'cancelled';

    /** The provider reported the payment did not succeed. */
    case PaymentFailed = 'payment_failed';

    /**
     * The checkout window closed before payment arrived.
     *
     * Separate from Cancelled, which is somebody's decision. This is the
     * clock, and it is what stops an abandoned checkout holding stock for
     * ever.
     */
    case PaymentExpired = 'payment_expired';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Processing => 'Processing',
            self::Fulfilled => 'Fulfilled',
            self::Cancelled => 'Cancelled',
            self::PaymentFailed => 'Payment failed',
            self::PaymentExpired => 'Checkout expired',
        };
    }

    /**
     * Whether the platform has been paid for this order.
     */
    public function isPaid(): bool
    {
        return match ($this) {
            self::Paid, self::Processing, self::Fulfilled => true,
            self::PendingPayment, self::Cancelled, self::PaymentFailed, self::PaymentExpired => false,
        };
    }

    /**
     * Whether a payment may still be attempted against this order.
     */
    public function acceptsPayment(): bool
    {
        return $this === self::PendingPayment;
    }

    /**
     * Whether this order is holding a unit of stock aside for itself.
     *
     * Only while it is waiting to be paid for: once paid the reservation has
     * become a sale, and once cancelled or expired it has been given back.
     */
    public function holdsReservation(): bool
    {
        return $this === self::PendingPayment;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the commercial figures on this order are now historical fact.
     *
     * From the moment money is verified, the amounts describe a transaction
     * that happened. Nothing may edit them afterwards -- a correction is a
     * separate, explicit financial act, not a change to the original record.
     */
    public function isCommerciallyFrozen(): bool
    {
        return $this->isPaid();
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The only legal moves.
     *
     * Note what is missing: nothing returns to PendingPayment, and nothing
     * reaches Paid except from PendingPayment. An order cannot be un-paid, and
     * a cancelled or expired order is not revived -- a customer who still
     * wants the item starts a new checkout, so the abandoned one stays on
     * record as what it was.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [
                self::Paid, self::Cancelled, self::PaymentFailed, self::PaymentExpired,
            ],
            self::Paid => [self::Processing, self::Fulfilled],
            self::Processing => [self::Fulfilled],
            self::Fulfilled => [],
            // Terminal. A refund is a later stage with its own records, and is
            // not expressible as a status change here.
            self::Cancelled, self::PaymentFailed, self::PaymentExpired => [],
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Paid, self::Fulfilled => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Processing => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::PendingPayment => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::Cancelled, self::PaymentExpired => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::PaymentFailed => 'bg-red-50 text-red-800 ring-red-200',
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

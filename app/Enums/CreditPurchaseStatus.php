<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a credit package purchase.
 *
 * The distinction that matters most: PAID means the provider has confirmed
 * money was received; FULFILLED means credits have actually been posted to the
 * ledger. A purchase must never be marked FULFILLED before the credits exist,
 * because that state is what stops a retry from granting them a second time --
 * and a payment that was taken but never fulfilled has to stay visibly
 * outstanding rather than looking complete.
 */
enum CreditPurchaseStatus: string
{
    /** Created; the customer has not been sent to the provider yet. */
    case Pending = 'pending';

    /** Handed off to the payment provider; awaiting the outcome. */
    case PaymentProcessing = 'payment_processing';

    /** The provider confirmed payment, verified server-side. Credits not yet posted. */
    case Paid = 'paid';

    /** Credits have been posted to the ledger. Terminal, successful. */
    case Fulfilled = 'fulfilled';

    /** The payment did not succeed. */
    case Failed = 'failed';

    /** Abandoned before payment. */
    case Cancelled = 'cancelled';

    /** Payment was reversed or refunded by the provider after settling. */
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::PaymentProcessing => 'Processing payment',
            self::Paid => 'Paid, awaiting credits',
            self::Fulfilled => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Reversed => 'Reversed',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Fulfilled, self::Failed, self::Cancelled, self::Reversed => true,
            default => false,
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Fulfilled;
    }

    /**
     * Whether credits have already been posted for this purchase.
     */
    public function hasCredits(): bool
    {
        return $this === self::Fulfilled || $this === self::Reversed;
    }

    /**
     * Only these transitions are legal. Anything else is a bug, and a payment
     * state machine that permits arbitrary moves is how a purchase ends up
     * fulfilled twice or marked complete without credits.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::PaymentProcessing, self::Paid, self::Failed, self::Cancelled],
            // Paid is reachable directly from processing; a fast provider can
            // confirm before the customer's browser returns.
            self::PaymentProcessing => [self::Paid, self::Failed, self::Cancelled],
            self::Paid => [self::Fulfilled, self::Failed, self::Reversed],
            self::Fulfilled => [self::Reversed],
            self::Failed, self::Cancelled, self::Reversed => [],
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Fulfilled => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Paid => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::Pending, self::PaymentProcessing => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Failed, self::Reversed => 'bg-red-50 text-red-800 ring-red-200',
            self::Cancelled => 'bg-amber-50 text-amber-800 ring-amber-200',
        };
    }
}

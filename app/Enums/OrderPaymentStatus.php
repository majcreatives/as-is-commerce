<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The state of one attempt to pay for one order.
 *
 * An order may have several attempts: a customer who abandons a payment page
 * and comes back gets a new one. Each keeps the amount and reference it was
 * actually opened with, so what was asked of the provider is never
 * reconstructed from an order that may have moved on.
 *
 * NO BROWSER REACHES `Success`. A redirect back from the provider is a browser
 * arriving, nothing more -- the customer may have abandoned the payment,
 * pressed back, or edited the URL. The only path into Success runs through a
 * server-to-server verification whose answer matched this attempt's frozen
 * reference, amount and currency.
 *
 * This mirrors {@see CreditPurchaseStatus}, which does the same job for credit
 * purchases, deliberately: same problem, same vocabulary.
 */
enum OrderPaymentStatus: string
{
    /** Created here; the provider has not been contacted yet. */
    case Initiated = 'initiated';

    /** Handed off to the provider; awaiting the outcome. */
    case Pending = 'pending';

    /** Verified server-side with the provider. Money is in. */
    case Success = 'success';

    /** The provider reported the payment did not succeed. */
    case Failed = 'failed';

    /** Opened and never completed. */
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Preparing',
            self::Pending => 'Awaiting payment',
            self::Success => 'Paid',
            self::Failed => 'Failed',
            self::Abandoned => 'Abandoned',
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Success;
    }

    /**
     * Whether this attempt is still capable of succeeding.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Initiated, self::Pending => true,
            self::Success, self::Failed, self::Abandoned => false,
        };
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
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Success is reachable straight from Initiated: a provider can
            // confirm before our own handoff bookkeeping has settled.
            self::Initiated => [self::Pending, self::Success, self::Failed, self::Abandoned],
            self::Pending => [self::Success, self::Failed, self::Abandoned],
            // Terminal. A successful payment is a fact; reversing one is a
            // refund, which has its own records and is not a status change.
            self::Success, self::Failed, self::Abandoned => [],
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Success => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Pending => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::Initiated => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Failed => 'bg-red-50 text-red-800 ring-red-200',
            self::Abandoned => 'bg-slate-100 text-slate-600 ring-slate-200',
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

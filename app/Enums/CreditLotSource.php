<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a batch of credits came from.
 *
 * Credits are not fungible. Promotional credits may expire, may be
 * non-refundable, and may be restricted in ways purchased credits are not, so
 * the system has to know which ones it is spending -- a single flattened
 * balance could not answer that.
 */
enum CreditLotSource: string
{
    case Purchased = 'purchased';
    case Promotional = 'promotional';
    case Referral = 'referral';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Purchased => 'Purchased',
            self::Promotional => 'Promotional',
            self::Referral => 'Referral reward',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * Order in which sources are consumed. Lower is spent first.
     *
     * Promotional credits go first because they are the ones most likely to
     * expire unused, so spending them first is the outcome that favours the
     * customer. Purchased credits -- the ones the customer actually paid for
     * and which do not expire by default -- are preserved longest.
     */
    public function consumptionPriority(): int
    {
        return match ($this) {
            self::Promotional => 0,
            self::Referral => 1,
            self::Adjustment => 2,
            self::Purchased => 3,
        };
    }

    /**
     * Whether credits from this source were paid for with real money.
     *
     * Relevant to refunds: only purchased credits represent money received.
     */
    public function isPaid(): bool
    {
        return $this === self::Purchased;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Purchased => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::Promotional => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::Referral => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Adjustment => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }
}

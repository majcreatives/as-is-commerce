<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What caused a movement of bidding credits.
 *
 * Sign convention, applied without exception across the ledger:
 *
 *   positive amount = credits added to the wallet
 *   negative amount = credits removed from the wallet
 *
 * Each case declares which direction it may move, so a mis-signed amount is
 * rejected before it can reach the ledger rather than quietly inverting a
 * customer's balance.
 */
enum CreditTransactionType: string
{
    /** Credits bought with real money. */
    case Purchase = 'purchase';

    /** Credits granted by a promotion or campaign. */
    case PromotionalCredit = 'promotional_credit';

    /** Credits granted for referring another user. */
    case ReferralCredit = 'referral_credit';

    /** Credits granted by an administrator. */
    case AdjustmentCredit = 'adjustment_credit';

    /** Credits consumed by placing a valid bid. Used by the auction stage. */
    case BidDebit = 'bid_debit';

    /** Credits returned to the user. */
    case Refund = 'refund';

    /** Unused credits that reached their expiry date. */
    case Expiration = 'expiration';

    /** Compensating entry that undoes an earlier transaction. */
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Credit purchase',
            self::PromotionalCredit => 'Promotional credits',
            self::ReferralCredit => 'Referral reward',
            self::AdjustmentCredit => 'Administrator adjustment',
            self::BidDebit => 'Auction bid',
            self::Refund => 'Refund',
            self::Expiration => 'Credits expired',
            self::Reversal => 'Reversal',
        };
    }

    /**
     * Whether this type adds credits to the wallet.
     */
    public function isCredit(): bool
    {
        return match ($this) {
            self::Purchase,
            self::PromotionalCredit,
            self::ReferralCredit,
            self::AdjustmentCredit,
            self::Refund => true,

            self::BidDebit,
            self::Expiration => false,

            // A reversal takes the opposite direction of whatever it undoes,
            // so it is the one type whose sign cannot be fixed here.
            self::Reversal => true,
        };
    }

    public function isDebit(): bool
    {
        return ! $this->isCredit();
    }

    /**
     * Whether an amount of the given sign is valid for this type.
     *
     * Reversals may go either way; everything else has one legal direction.
     */
    public function allowsAmount(int $amount): bool
    {
        if ($amount === 0) {
            return false;
        }

        if ($this === self::Reversal) {
            return true;
        }

        return $this->isCredit() ? $amount > 0 : $amount < 0;
    }

    /**
     * Whether this type creates a new lot of spendable credits.
     */
    public function createsLot(): bool
    {
        return match ($this) {
            self::Purchase,
            self::PromotionalCredit,
            self::ReferralCredit,
            self::AdjustmentCredit,
            self::Refund => true,
            default => false,
        };
    }

    /**
     * The lot source a credit of this type produces.
     */
    public function lotSource(): ?CreditLotSource
    {
        return match ($this) {
            self::Purchase => CreditLotSource::Purchased,
            self::PromotionalCredit => CreditLotSource::Promotional,
            self::ReferralCredit => CreditLotSource::Referral,
            self::AdjustmentCredit, self::Refund => CreditLotSource::Adjustment,
            default => null,
        };
    }
}

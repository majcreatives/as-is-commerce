<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What caused a movement of real money.
 *
 * Deliberately a separate vocabulary from {@see CreditTransactionType}.
 * Cash and bidding credits are different things with different rules, and a
 * shared enum would be the first step towards a shared balance -- which is
 * exactly the mistake this architecture avoids.
 *
 * Amounts follow the same sign convention: positive adds, negative removes.
 */
enum CashTransactionType: string
{
    /** Money received from the customer. */
    case Deposit = 'deposit';

    /** Money spent buying bidding credits. */
    case CreditPurchase = 'credit_purchase';

    /** Money returned to the customer. */
    case Refund = 'refund';

    /** Money paid out to the customer. */
    case Withdrawal = 'withdrawal';

    /** Correction made by an administrator. */
    case Adjustment = 'adjustment';

    /** Compensating entry that undoes an earlier transaction. */
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::CreditPurchase => 'Credit purchase',
            self::Refund => 'Refund',
            self::Withdrawal => 'Withdrawal',
            self::Adjustment => 'Adjustment',
            self::Reversal => 'Reversal',
        };
    }

    public function isCredit(): bool
    {
        return match ($this) {
            self::Deposit, self::Refund => true,
            self::CreditPurchase, self::Withdrawal => false,
            // Adjustments and reversals may go either way.
            self::Adjustment, self::Reversal => true,
        };
    }

    public function allowsAmount(int $amountMinor): bool
    {
        if ($amountMinor === 0) {
            return false;
        }

        if ($this === self::Reversal || $this === self::Adjustment) {
            return true;
        }

        return $this->isCredit() ? $amountMinor > 0 : $amountMinor < 0;
    }
}

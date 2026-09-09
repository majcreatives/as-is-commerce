<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What caused a movement of Store Wallet credit.
 *
 * Store Wallet credit is non-withdrawable purchasing power inside the
 * platform. It is not cash, not a bidding credit, and not convertible into
 * either. These cases are the complete list of things that may move it, and
 * that shortness is the point: there is no adjustment case, no transfer case
 * and no conversion case, because none of those are things the business has
 * decided to allow.
 *
 * Sign convention, applied without exception:
 *
 *   positive amount = value added to the wallet
 *   negative amount = value removed from the wallet
 */
enum StoreWalletTransactionType: string
{
    /**
     * A losing bidder's consumed purchased credits, valued at what they
     * actually cost, issued when the auction they were spent on was won by
     * somebody else.
     */
    case AuctionLossCompensation = 'auction_loss_compensation';

    /**
     * Value committed to a fixed-price catalogue checkout.
     *
     * Taken at checkout rather than at payment, so two checkouts cannot both
     * plan to spend the same value. The order it is committed to is the
     * transaction's reference.
     */
    case OrderApplied = 'order_applied';

    /**
     * Value returned because the checkout it was committed to closed without
     * being paid -- it expired, or it was cancelled.
     */
    case OrderReleased = 'order_released';

    public function label(): string
    {
        return match ($this) {
            self::AuctionLossCompensation => 'Auction credit value',
            self::OrderApplied => 'Applied to an order',
            self::OrderReleased => 'Returned from a closed order',
        };
    }

    /**
     * Whether this type adds value to the wallet.
     */
    public function isCredit(): bool
    {
        return match ($this) {
            self::AuctionLossCompensation, self::OrderReleased => true,
            self::OrderApplied => false,
        };
    }

    public function isDebit(): bool
    {
        return ! $this->isCredit();
    }

    /**
     * Whether an amount of the given sign is valid for this type.
     *
     * Every case has exactly one legal direction. Unlike the credit ledger
     * there is no reversal case that could go either way, so a mis-signed
     * amount is always a bug.
     */
    public function allowsAmount(int $amountMinor): bool
    {
        if ($amountMinor === 0) {
            return false;
        }

        return $this->isCredit() ? $amountMinor > 0 : $amountMinor < 0;
    }

    /**
     * Whether this type records value arriving from consumed credits, and so
     * carries a per-lot attribution of where that value came from.
     */
    public function carriesCreditSources(): bool
    {
        return $this === self::AuctionLossCompensation;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::AuctionLossCompensation => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::OrderApplied => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::OrderReleased => 'bg-brand-50 text-brand-800 ring-brand-200',
        };
    }
}

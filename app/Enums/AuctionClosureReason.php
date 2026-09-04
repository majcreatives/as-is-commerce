<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an auction stopped.
 *
 * Kept separate from {@see AuctionStatus} because two very different endings
 * both leave the auction Settled, and the system must be able to tell them
 * apart without inference:
 *
 *   HighestBid  the auction ran its course and the highest valid credit bid
 *               won. The winner owes the auction settlement amount.
 *
 *   BuyNow      somebody bought the product outright and the auction ended
 *               immediately. The standing highest bidder did not win, and no
 *               settlement amount is owed by anyone.
 *
 * Recording this explicitly is what stops a Buy Now termination being read
 * later as a normal highest-bid settlement, which would misstate both who
 * acquired the product and what was paid for it.
 */
enum AuctionClosureReason: string
{
    /** Closed on the clock; the highest valid credit bid won. */
    case HighestBid = 'highest_bid';

    /** A Buy Now purchase completed successfully and ended the auction. */
    case BuyNow = 'buy_now';

    /** Closed on the clock with no bids to choose a winner from. */
    case NoBids = 'no_bids';

    /** Stopped by an administrator. */
    case Cancelled = 'cancelled';

    /** The winner did not settle in time. */
    case Forfeited = 'forfeited';

    public function label(): string
    {
        return match ($this) {
            self::HighestBid => 'Won by the highest bid',
            self::BuyNow => 'Ended by Buy Now',
            self::NoBids => 'No bids were placed',
            self::Cancelled => 'Cancelled by an administrator',
            self::Forfeited => 'Winner did not settle',
        };
    }

    /**
     * Whether this ending produced a highest-bid winner.
     *
     * False for Buy Now specifically: someone acquired the product, but not
     * as the auction's winner.
     */
    public function producedBidWinner(): bool
    {
        return $this === self::HighestBid;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::HighestBid => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::BuyNow => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::NoBids => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Cancelled, self::Forfeited => 'bg-red-50 text-red-800 ring-red-200',
        };
    }
}

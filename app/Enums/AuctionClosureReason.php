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
 *   PotTargetReached  the pot-target bidding rule closed the auction early:
 *               the sum of every accepted bid reached the auction's own
 *               `pot_target_credits`, and the standing leader at that instant
 *               won -- exactly as with HighestBid, on their own highest
 *               total, just earlier than the clock would have decided it.
 *               Kept distinct from HighestBid so an administrator can see
 *               afterwards which of the two ways a given auction actually
 *               ended (docs/PLAN_POT_TARGET_BIDDING.md, D-6).
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

    /** Closed early because the pot target was reached; the standing leader won. */
    case PotTargetReached = 'pot_target_reached';

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
            self::PotTargetReached => 'Won when the pot target was reached',
            self::BuyNow => 'Ended by Buy Now',
            self::NoBids => 'No bids were placed',
            self::Cancelled => 'Cancelled by an administrator',
            self::Forfeited => 'Winner did not settle',
        };
    }

    /**
     * Whether this ending produced a highest-bid winner.
     *
     * True for PotTargetReached as well as HighestBid: both are the standing
     * leader winning on their own highest total, decided under the same
     * winner rule -- pot-target closing only changes *when* that moment
     * arrives, never *how* the winner is chosen. False for Buy Now
     * specifically: someone acquired the product, but not as the auction's
     * winner.
     */
    public function producedBidWinner(): bool
    {
        return $this === self::HighestBid || $this === self::PotTargetReached;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::HighestBid, self::PotTargetReached => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::BuyNow => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::NoBids => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Cancelled, self::Forfeited => 'bg-red-50 text-red-800 ring-red-200',
        };
    }
}

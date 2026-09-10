<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Contracts\AuctionLossCompensation;
use App\Domain\Auction\Contracts\SettlementHandoff;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Enums\AuctionStatus;
use App\Events\AuctionForfeited;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The winner let the settlement deadline pass.
 *
 * Two things have to happen together, which is why this exists rather than a
 * bare call to the lifecycle: the auction forfeits and releases its unit, and
 * the winner's outstanding checkout closes. Doing only the first would leave a
 * payable order against a unit that had already gone back on sale -- a
 * customer could pay for something the platform had just resold.
 *
 * NO CREDITS COME BACK. The winner's bid credits were consumed when the bids
 * were placed and stay consumed. Forfeiting is the loss of the right to buy,
 * not a reversal of the bidding. The frozen `forfeit_policy` records what the
 * auction was created under; nothing here invents a policy beyond it.
 *
 * Idempotent: an auction that has already forfeited is not in
 * `PendingSettlement` any more and is returned untouched, so a sweep running
 * twice forfeits once.
 */
final class ForfeitAuction
{
    public function __construct(
        private readonly AuctionLifecycle $lifecycle,
        private readonly SettlementHandoff $settlement,
        private readonly AuctionLossCompensation $compensation,
    ) {}

    public function handle(Auction $auction, ?User $actor = null): Auction
    {
        $before = $auction->status;

        $forfeited = DB::transaction(function () use ($auction, $actor): Auction {
            $locked = $this->lifecycle->lock($auction);

            // Somebody settled, or another sweep got here first. Both are
            // ordinary, and neither is an error.
            if ($locked->status !== AuctionStatus::PendingSettlement) {
                return $locked;
            }

            // Closed first, while the auction is still the thing it was: an
            // order cancelled after the unit went back on sale would be racing
            // whoever bought it next.
            $this->settlement->closeFor(
                $locked,
                'The auction settlement deadline passed without payment.',
            );

            $forfeited = $this->lifecycle->forfeit($locked, $actor);

            // Nobody ended up with the product. Every bidder -- the forfeited
            // winner included -- gets the value of their purchased credits
            // back as Store Wallet credit. The credits themselves stay
            // consumed, exactly as before.
            $this->compensation->compensateLosers($forfeited, null);

            return $forfeited;
        });

        // After commit, and only when this call did the forfeiting: repeated
        // sweeps must not tell the winner twice.
        if ($before === AuctionStatus::PendingSettlement
            && $forfeited->status === AuctionStatus::Forfeited) {
            AuctionForfeited::dispatch($forfeited);
        }

        return $forfeited;
    }
}

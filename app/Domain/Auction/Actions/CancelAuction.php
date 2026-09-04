<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Contracts\SettlementHandoff;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An administrator stops an auction.
 *
 * Wraps the lifecycle transition so that cancelling an auction which had
 * already named a winner also closes that winner's outstanding checkout.
 * Without it, a cancelled auction would release its unit while leaving a
 * payable order pointing at it.
 *
 * NO CREDITS COME BACK. Bids placed before the cancellation keep their
 * consumed credits: they were spent on the attempt, and deciding whether the
 * platform owes anything for stopping an auction is a business decision taken
 * deliberately and posted to the ledger as its own entry -- not something this
 * action may infer.
 */
final class CancelAuction
{
    public function __construct(
        private readonly AuctionLifecycle $lifecycle,
        private readonly SettlementHandoff $settlement,
    ) {}

    public function handle(Auction $auction, string $reason, ?User $actor = null): Auction
    {
        return DB::transaction(function () use ($auction, $reason, $actor): Auction {
            $locked = $this->lifecycle->lock($auction);

            // Closed before the unit is released, so nothing can be paid for
            // stock that is on its way back to the shelf.
            $this->settlement->closeFor($locked, "The auction was cancelled: {$reason}");

            return $this->lifecycle->cancel($locked, $reason, $actor);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Auction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A winner let the settlement deadline pass.
 *
 * The winner is told that the auction is over and, plainly, that the credits
 * they bid remain consumed. Forfeiting is the loss of the right to buy, not a
 * reversal of the bidding, and a message that left that ambiguous would invite
 * a support conversation about a refund that does not exist.
 */
final readonly class AuctionForfeited
{
    use Dispatchable;

    public function __construct(
        public Auction $auction,
    ) {}
}

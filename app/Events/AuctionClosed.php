<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Auction;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An auction stopped on its own clock.
 *
 * Covers both endings the clock produces: a winner on the highest valid credit
 * bid, and no bids at all. Buy Now has its own event, because a product being
 * bought outright is a different thing to tell people about and the auction
 * did not run its course.
 *
 * Dispatched after the closing transaction commits. Losing bidders are worked
 * out by the listener from the bid records rather than passed in, so the list
 * cannot go stale between the closure and the notification.
 */
final readonly class AuctionClosed
{
    use Dispatchable;

    public function __construct(
        public Auction $auction,
    ) {}
}

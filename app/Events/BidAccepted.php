<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Bid;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A bid was accepted and its credits consumed.
 *
 * Dispatched after the placing transaction has committed, never inside it. A
 * notification written inside a transaction that later rolled back would tell
 * somebody about a bid that does not exist.
 *
 * Carries the bid rather than a bundle of scalars, because everything a
 * listener needs -- the auction, the product, the amount, the bidder -- hangs
 * off it, and a listener reading the record reads what was actually saved.
 */
final readonly class BidAccepted
{
    use Dispatchable;

    public function __construct(
        public Bid $bid,
        /** Whoever this bid displaced, if it displaced anybody. */
        public ?Bid $previousHighest = null,
        /** Seconds the clock gained, when this bid extended the auction. */
        public int $extendedBySeconds = 0,
    ) {}
}

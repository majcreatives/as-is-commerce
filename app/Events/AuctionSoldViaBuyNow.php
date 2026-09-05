<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody bought the product outright and the auction ended.
 *
 * The buyer is carried explicitly; every other participant is worked out by
 * the listener. The buyer's identity is never disclosed to those participants
 * -- they are told the product was sold, not who bought it.
 */
final readonly class AuctionSoldViaBuyNow
{
    use Dispatchable;

    public function __construct(
        public Auction $auction,
        public User $buyer,
    ) {}
}

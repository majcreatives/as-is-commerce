<?php

declare(strict_types=1);

namespace App\Domain\Auction\Contracts;

use App\Models\Auction;

/**
 * How an ending auction gives its losing bidders back what their purchased
 * credits actually cost.
 *
 * An interface rather than a direct call, for the same reason
 * {@see SettlementHandoff} is one: the Store Wallet domain reads bids and
 * auctions to value what was consumed, so calling back the other way would
 * tie the two together in both directions. The auction domain states what it
 * needs; the Store Wallet domain provides it, bound in `AppServiceProvider`.
 *
 * WHAT AN IMPLEMENTATION MUST NOT DO. It must not return bidding credits --
 * they are consumed permanently and stay consumed through every outcome. It
 * must not move cash, touch stock, alter the auction, or decide who won. It
 * issues purchasing power inside the shop and nothing else.
 *
 * IT MUST BE SAFE TO CALL TWICE, and this matters more here than almost
 * anywhere: closing is idempotent and re-runs on every sweep, so an
 * implementation that issued on each call would multiply a customer's balance
 * by the number of times a worker retried. The uniqueness must be enforced by
 * the database, keyed on the auction and the user, not by checking first.
 *
 * WHO IS COMPENSATED. Everyone who bid and did not end up with the product.
 * The winner is not, because they won; a Buy Now buyer is not, because their
 * credits' value already came off what they paid. Compensating either would be
 * counting the same value twice.
 */
interface AuctionLossCompensation
{
    /**
     * Issue Store Wallet value to everyone who bid on this auction and lost.
     *
     * Called inside the closing or Buy Now transaction, with the auction row
     * already locked, so the set of bidders cannot change underneath it.
     *
     * @param  Auction  $auction  The auction that has just ended.
     * @param  int|null  $acquiredByUserId  Whoever ended up with the product --
     *                                      the winning bidder, or the Buy Now
     *                                      buyer. Excluded from compensation.
     *                                      Null when nobody acquired it.
     * @return array<int, int> User id => minor units issued. Users whose
     *                         consumed credits were worth nothing are absent
     *                         rather than present at zero.
     */
    public function compensateLosers(Auction $auction, ?int $acquiredByUserId): array;
}

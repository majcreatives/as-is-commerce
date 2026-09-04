<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Auction\Exceptions\HighestBidMutationForbidden;
use Closure;

/**
 * Makes an auction's highest-bid columns unwritable from ordinary code.
 *
 * The bid records are authoritative. `highest_bid_id`, `highest_bid_credits`
 * and `bid_count` are a cache of the query over them, kept so a listing page
 * does not aggregate the bid table once per row.
 *
 * Guarding them matters more here than for stock or balances, because this is
 * the number that decides who wins. A controller doing
 * `$auction->highest_bid_credits = 500` would be inventing a winner. The rule
 * is that these columns are only ever written from the bid records, and the
 * only code allowed to do it is the resolver that reads them.
 *
 * An update that changes one is rejected unless it happens inside that
 * resolver, which opens a short process-local window around its own write.
 * The window closes in a finally block, so a thrown exception cannot leave it
 * open.
 *
 * The guard covers updates rather than inserts: a new auction starts with no
 * bids, none of these columns is fillable, and nothing in the request path can
 * set them.
 *
 * This mirrors {@see GuardsMaterializedStock} and
 * {@see GuardsMaterializedBalance} deliberately: same problem, same shape of
 * answer.
 */
trait GuardsMaterializedHighestBid
{
    /** @var list<string> */
    private static array $highestBidColumns = ['highest_bid_id', 'highest_bid_credits', 'bid_count'];

    private static bool $highestBidWritesPermitted = false;

    /**
     * Run a callback with the projection writable.
     *
     * Only the highest-bid resolver should call this, and only around a write
     * it has just computed from the bid records.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function permittingHighestBidWrites(Closure $callback): mixed
    {
        $previous = self::$highestBidWritesPermitted;
        self::$highestBidWritesPermitted = true;

        try {
            return $callback();
        } finally {
            self::$highestBidWritesPermitted = $previous;
        }
    }

    protected static function bootGuardsMaterializedHighestBid(): void
    {
        static::updating(function (self $model): void {
            if (self::$highestBidWritesPermitted) {
                return;
            }

            foreach (self::$highestBidColumns as $column) {
                if ($model->isDirty($column)) {
                    throw HighestBidMutationForbidden::for($column);
                }
            }
        });
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the highest-bid lookup an index it can actually sort with.
 *
 * THE QUERY. `Bid::scopeLeadingFirst()` orders `amount_credits DESC,
 * sequence ASC` -- highest first, earliest first among equals. That tie-break
 * is load-bearing: it is what makes "the highest valid credit bid wins" name
 * exactly one bidder when two commit the same amount.
 *
 * THE DEFECT. `bids_highest_bid_index` was created ascending on both columns,
 * and its comment claimed MySQL would "walk this index backwards on amount and
 * forwards on sequence". It cannot. One index scan has one direction. A mixed
 * `DESC, ASC` ordering can only be served by an index whose column directions
 * match, and MySQL 8 supports exactly that -- descending index columns -- which
 * is what this migration supplies.
 *
 * Measured before the change, with the index forced so cardinality could not
 * explain the result away:
 *
 *   ORDER BY amount_credits DESC, sequence ASC   ->  Using where; Using filesort
 *   ORDER BY amount_credits DESC, sequence DESC  ->  Using where; Backward index scan
 *
 * Only the sort direction differs between those two, so the index shape was the
 * cause rather than the data.
 *
 * WHY IT MATTERS. This is the hottest query on the platform. It runs on every
 * poll of every viewer of a live auction, twice per bid placement (once
 * `FOR UPDATE`), on every projection rebuild, and on every auction close. On an
 * auction with thousands of bids, a filesort sorted the whole set to return one
 * row.
 *
 * A REPLACEMENT, NOT AN ADDITION. Same three columns, same width, corrected
 * direction -- so the old index is dropped rather than left alongside. `bids`
 * is written on the bid path, and a redundant index would be a write cost paid
 * on every bid for nothing.
 *
 * `status` is deliberately not included. `BidStatus` has one case, a rejected
 * bid leaves no row at all, and adding a 20-character column for zero
 * selectivity would widen every entry on a table that is inserted into under a
 * row lock. Leaving it out also keeps this a strict drop-in for the index it
 * replaces.
 *
 * NOTHING ABOUT BEHAVIOUR CHANGES. Not the winner rule, not the tie-break, not
 * `HighestBidResolver`, not bid semantics. The same query returns the same row
 * in the same order; it simply stops sorting to find it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw SQL because Laravel's schema builder has no way to express a
        // per-column index direction, and the direction is the entire point.
        DB::statement('ALTER TABLE `bids` DROP INDEX `bids_highest_bid_index`');

        DB::statement(
            'ALTER TABLE `bids` ADD INDEX `bids_highest_bid_index` '
            .'(`auction_id`, `amount_credits` DESC, `sequence` ASC)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `bids` DROP INDEX `bids_highest_bid_index`');

        DB::statement(
            'ALTER TABLE `bids` ADD INDEX `bids_highest_bid_index` '
            .'(`auction_id`, `amount_credits`, `sequence`)'
        );
    }
};

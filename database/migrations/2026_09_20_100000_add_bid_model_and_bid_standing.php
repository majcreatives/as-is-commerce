<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two additive columns for the cumulative bidding model. Nothing reads either
 * yet, and no existing row or behaviour changes.
 *
 * RULESET: `auction_rulesets.bid_model`. Which model auctions created from this
 * ruleset will follow. Every existing ruleset is `single_highest` -- the
 * default -- so an existing ruleset does NOT silently start producing a new kind
 * of auction, whatever numbers it holds. Adopting the new model is an explicit
 * act on a new ruleset version, never a side effect of a deploy.
 *
 * The CHECK names both values the model will have. The application enum
 * carries only the one it can honour today, so a row naming the other fails
 * loudly on load rather than being ranked as something it is not.
 *
 * BIDS: `bids.cumulative_credits`. Where a bid leaves its bidder: the credits
 * that bidder has consumed on that auction INCLUDING this bid. It is written
 * once, at insert, under the auction row lock, and never updated -- the
 * append-only triggers already refuse an UPDATE, so there is nothing new to
 * guard.
 *
 * Nullable, and left NULL for every existing bid. Under the single-highest
 * model the number that ranks is `amount_credits`, and a running total is a
 * fact nobody needed. Backfilling would mean UPDATE-ing an append-only table
 * to record something the old rules never used, to no benefit, and would put
 * history in a state it was never in.
 *
 * The CHECK states the one thing that must always be true of a standing: it can
 * never be less than what the bid itself consumed.
 *
 * The index is the leader lookup for the new model. `amount_credits DESC,
 * sequence ASC` is served by `bids_highest_bid_index`; this is its twin for the
 * running total, with the same shape and the same tie-break, so ranking stays
 * an index walk rather than a sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_rulesets', function (Blueprint $table): void {
            $table->string('bid_model', 20)->default('single_highest')->after('allow_bid_increase');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD CONSTRAINT chk_rulesets_bid_model CHECK (
                bid_model IN ('single_highest', 'cumulative_step')
            )
        SQL);

        Schema::table('bids', function (Blueprint $table): void {
            $table->unsignedBigInteger('cumulative_credits')->nullable()->after('amount_credits');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bids
            ADD CONSTRAINT chk_bids_standing_covers_bid CHECK (
                cumulative_credits IS NULL OR cumulative_credits >= amount_credits
            )
        SQL);

        // Raw SQL: the schema builder cannot express a per-column direction,
        // and the direction is the point (see the 2026_09_08 index migration).
        DB::statement(
            'ALTER TABLE `bids` ADD INDEX `bids_standing_index` '
            .'(`auction_id`, `cumulative_credits` DESC, `sequence` ASC)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `bids` DROP INDEX `bids_standing_index`');
        DB::statement('ALTER TABLE bids DROP CONSTRAINT chk_bids_standing_covers_bid');

        Schema::table('bids', function (Blueprint $table): void {
            $table->dropColumn('cumulative_credits');
        });

        DB::statement('ALTER TABLE auction_rulesets DROP CONSTRAINT chk_rulesets_bid_model');

        Schema::table('auction_rulesets', function (Blueprint $table): void {
            $table->dropColumn('bid_model');
        });
    }
};

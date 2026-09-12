<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrects the ruleset from Last Bidder Standing to Highest Valid Credit Bid.
 *
 * The rules engine was built for an auction where the leader when the clock
 * expired won. The definitive model is different: the participant holding the
 * highest valid credit bid at normal closure wins, whether or not they were
 * ever continuously in the lead.
 *
 * Three columns are dropped rather than reinterpreted, because each carries a
 * meaning that is wrong under the new model and silently repurposing them
 * would leave future developers implementing the obsolete one:
 *
 *   unique_leader                  A "leader" who must not hold the lead twice
 *                                  in a row is meaningless when the winner is
 *                                  simply whoever bid highest.
 *
 *   bid_cost_credits               A fixed cost per bid action. Bids now carry
 *                                  their own variable amount, so a per-bid
 *                                  price is not a rule the engine can use.
 *
 *   default_checkout_price_minor   A settlement price. What a normal auction
 *                                  winner pays has deliberately not been
 *                                  decided, so encoding an amount here would
 *                                  be inventing that decision.
 *
 * Timing is kept. Late-bid extension is still meaningful under a highest-bid
 * auction -- it stops sniping -- but it is now independent of who wins, and
 * the business rule for it is unfinalized, so new rulesets are seeded with
 * extensions switched off rather than with values nobody chose.
 *
 * Existing rows keep the timing values an administrator already set. This is a
 * schema correction rather than a configuration change, and rewriting live
 * settings would be a different act than the one being asked for here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The CHECK constraint references a column that is about to go.
        DB::statement('ALTER TABLE auction_rulesets DROP CONSTRAINT chk_rulesets_bid_cost');
        DB::statement('ALTER TABLE auction_rulesets DROP CONSTRAINT chk_rulesets_checkout_price');

        Schema::table('auction_rulesets', function (Blueprint $table) {
            // ---- Bidding, under the corrected model ---------------------
            //
            // Nullable throughout: the business values have not been decided,
            // and null means "no rule" rather than a number nobody chose.
            // Inventing a minimum bid to fill the schema would be worse than
            // leaving it unset, because a wrong number looks like a decision.

            $table->unsignedBigInteger('minimum_bid_credits')->nullable()
                ->after('description');

            $table->unsignedBigInteger('minimum_bid_increment_credits')->nullable()
                ->after('minimum_bid_credits');

            // Whether a participant may raise their own standing highest bid.
            // Null means undecided, which is different from either answer.
            $table->boolean('allow_bid_increase')->nullable()
                ->after('minimum_bid_increment_credits');

            // ---- Buy Now -------------------------------------------------

            $table->boolean('buy_now_enabled')->default(true)
                ->after('forfeit_policy');

            $table->boolean('buy_now_credit_discount_enabled')->default(true)
                ->after('buy_now_enabled');

            // How much GHS one consumed bid credit takes off the Buy Now
            // price, in minor units. 100 means one credit gives GH1.
            //
            // Stored as an explicit conversion rather than assumed in code, so
            // the rate is versioned with everything else and a past auction
            // stays explicable if it ever changes. This is the only place in
            // the system where credits relate to money at all, and it applies
            // solely to the Buy Now path.
            $table->unsignedBigInteger('buy_now_credit_discount_minor_per_credit')
                ->default(100)
                ->after('buy_now_credit_discount_enabled');
        });

        Schema::table('auction_rulesets', function (Blueprint $table) {
            $table->dropColumn([
                'unique_leader',
                'bid_cost_credits',
                'default_checkout_price_minor',
            ]);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD CONSTRAINT chk_rulesets_minimum_bid CHECK (
                minimum_bid_credits IS NULL OR minimum_bid_credits > 0
            ),
            ADD CONSTRAINT chk_rulesets_minimum_increment CHECK (
                minimum_bid_increment_credits IS NULL OR minimum_bid_increment_credits > 0
            ),
            ADD CONSTRAINT chk_rulesets_discount_rate CHECK (
                buy_now_credit_discount_minor_per_credit > 0
            )
        SQL);
    }

    /**
     * Rollback is refused, on purpose.
     *
     * Reversing this migration would resurrect the columns of the Last Bidder
     * Standing model -- `unique_leader`, `bid_cost_credits`,
     * `default_checkout_price_minor` -- and the codebase has asserted ever
     * since that none of them exist. Rolling back would hand every later
     * migration and the running application a schema the code actively
     * rejects. The only correct rollback is the whole database from backup;
     * a half-rolled-forward schema serving a corrected application is not a
     * state this migration may produce.
     *
     * @throws RuntimeException
     */
    public function down(): void
    {
        throw new RuntimeException(
            'This migration corrects the auction rules model to Highest Valid Credit Bid '
            .'and cannot be rolled back. Restore the database from backup instead.'
        );
    }
};

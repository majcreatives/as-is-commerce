<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The step a cumulative-model ruleset carries: `bid_increment_credits`.
 *
 * A NEW COLUMN, NOT A RENAME. `minimum_bid_increment_credits` means "a lower
 * bound over the leader" and always has, in every ruleset and in every frozen
 * snapshot. Renaming it, or quietly reading it as an exact step under a
 * different model, would leave a column whose name and meaning disagree in one
 * direction or the other. So it is left exactly as it is, for single-highest
 * rulesets, and the cumulative model gets a column that says what it holds.
 *
 * THREE CHECKS, so the database refuses the contradictions the application
 * also refuses:
 *
 *   a step is at least one credit
 *   a ruleset that is ACTIVE under the cumulative model has both a minimum bid
 *     (its opening bid, the only opening figure that is not invented) and a
 *     step. A draft may be incomplete -- somebody is still filling it in --
 *     but nothing incomplete can be put to use.
 *   the two models' fields do not mix: a cumulative ruleset carries none of
 *     the single-highest rules (a lower-bound increment, the raise-your-own-bid
 *     option, which has no meaning when a leader cannot bid), and a
 *     single-highest ruleset carries no step
 *
 * Every existing row is single-highest with no step, so all three hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_rulesets', function (Blueprint $table): void {
            $table->unsignedBigInteger('bid_increment_credits')->nullable()->after('minimum_bid_increment_credits');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD CONSTRAINT chk_rulesets_bid_increment CHECK (
                bid_increment_credits IS NULL OR bid_increment_credits >= 1
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD CONSTRAINT chk_rulesets_cumulative_active_complete CHECK (
                status <> 'active'
                OR bid_model <> 'cumulative_step'
                OR (minimum_bid_credits IS NOT NULL AND bid_increment_credits IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD CONSTRAINT chk_rulesets_bid_model_fields CHECK (
                (bid_model = 'cumulative_step'
                    AND minimum_bid_increment_credits IS NULL
                    AND allow_bid_increase IS NULL)
                OR (bid_model = 'single_highest' AND bid_increment_credits IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auction_rulesets DROP CONSTRAINT chk_rulesets_bid_model_fields');
        DB::statement('ALTER TABLE auction_rulesets DROP CONSTRAINT chk_rulesets_cumulative_active_complete');
        DB::statement('ALTER TABLE auction_rulesets DROP CONSTRAINT chk_rulesets_bid_increment');

        Schema::table('auction_rulesets', function (Blueprint $table): void {
            $table->dropColumn('bid_increment_credits');
        });
    }
};

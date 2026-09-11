<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns the schema default for the anti-snipe bid interval with the value
 * every other surface already registers.
 *
 * `minimum_bid_interval_ms` was created DEFAULTing to 1000 in the original
 * CREATE, while the seeder, the factory and the admin form all default to
 * 3000. The default only matters when an administrator creates a ruleset
 * without stating an interval, but a column that silently disagrees with the
 * three places operators actually see the rule is exactly how a decision
 * nobody made gets made by default. 3000 is the value already recorded in
 * three places; the column simply failed to record it too.
 *
 * A forward migration rather than an edit to the historical CREATE, per the
 * codebase convention: shipped migrations describe past state, and
 * production has already run them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE auction_rulesets ALTER COLUMN minimum_bid_interval_ms SET DEFAULT 3000');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auction_rulesets ALTER COLUMN minimum_bid_interval_ms SET DEFAULT 1000');
    }
};

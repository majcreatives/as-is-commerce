<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_rulesets', function (Blueprint $table) {
            $table->id();

            // Identity. A ruleset is a named lineage; each edit after
            // activation produces a new version rather than mutating history.
            $table->string('name', 100);
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();

            $table->string('status', 20)->default('draft');

            // ---- Bidding -----------------------------------------------
            $table->unsignedInteger('bid_cost_credits')->default(1);
            $table->boolean('unique_leader')->default(true);
            $table->unsignedInteger('minimum_bid_interval_ms')->default(1000);

            // ---- Timing (integer seconds) ------------------------------
            $table->unsignedInteger('base_duration_seconds');
            $table->unsignedInteger('closing_window_seconds')->default(10);
            $table->unsignedInteger('extension_seconds')->default(10);
            $table->unsignedInteger('max_extensions')->default(20);
            $table->unsignedInteger('max_extension_total_seconds')->default(300);

            // ---- Winner and checkout -----------------------------------
            $table->unsignedInteger('checkout_deadline_minutes')->default(60);
            $table->string('forfeit_policy', 30)->default('relist');

            // ---- Pricing (integer minor units, never float) -------------
            //
            // Nullable on purpose. A ruleset carries auction *defaults*; the
            // checkout price is a property of the product being auctioned and
            // is supplied when the auction is created. Leaving it null means
            // "no default -- the auction must state its own price", which is
            // preferable to inventing a placeholder figure here.
            $table->unsignedBigInteger('default_checkout_price_minor')->nullable();

            $table->unsignedBigInteger('delivery_fee_minor')->default(0);
            $table->char('currency', 3)->default('GHS');
            $table->unsignedInteger('tax_bps')->default(0);

            // Exactly one ruleset is the global default used when an auction
            // does not name one.
            $table->boolean('is_default')->default(false);

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['name', 'version']);
            $table->index(['status', 'name']);
        });

        // The database enforces the rules that matter, rather than trusting
        // every future caller to remember them.
        //
        // Generated columns give MySQL the partial-unique indexes it otherwise
        // lacks: the expression yields NULL for rows that should not
        // participate, and MySQL permits unlimited NULLs in a unique index.

        // At most one ACTIVE version per named lineage.
        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD COLUMN active_name VARCHAR(100)
                GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN name END) STORED,
            ADD UNIQUE INDEX auction_rulesets_active_name_unique (active_name)
        SQL);

        // At most one global default across the whole table.
        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD COLUMN default_marker TINYINT
                GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 END) STORED,
            ADD UNIQUE INDEX auction_rulesets_default_marker_unique (default_marker)
        SQL);

        // Numeric invariants, so a bad row cannot be written by any path --
        // application, console command, or a hand-run SQL statement.
        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD CONSTRAINT chk_rulesets_bid_cost CHECK (bid_cost_credits >= 1),
            ADD CONSTRAINT chk_rulesets_base_duration CHECK (base_duration_seconds > 0),
            ADD CONSTRAINT chk_rulesets_checkout_deadline CHECK (checkout_deadline_minutes > 0),
            ADD CONSTRAINT chk_rulesets_tax_bps CHECK (tax_bps <= 10000),
            ADD CONSTRAINT chk_rulesets_checkout_price CHECK (
                default_checkout_price_minor IS NULL OR default_checkout_price_minor > 0
            ),
            ADD CONSTRAINT chk_rulesets_closing_window CHECK (
                closing_window_seconds <= base_duration_seconds
            ),
            ADD CONSTRAINT chk_rulesets_status CHECK (
                status IN ('draft', 'active', 'archived')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_rulesets');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the flat Buy Now discount rate with actual lot-based valuation.
 *
 * The old economics were a system-wide conversion: every consumed bid credit
 * took GH1 off the Buy Now price, whatever the credits had cost. The new
 * model values each consumed credit at the price its own lot was bought at --
 * a GH0.10 credit reduces the price by GH0.10, a promotional credit by
 * nothing.
 *
 * THE RULESET. `buy_now_credit_discount_minor_per_credit` goes, with the CHECK
 * that held it. The switch that decides whether consumed credits reduce the
 * price at all (`buy_now_credit_discount_enabled`) stays; it now means "apply
 * the credits' cash value" rather than "apply a flat rate".
 *
 * THE SNAPSHOTS. `auctions.rules_snapshot` carries an immutable copy of the
 * rules under `$.rules`, versioned by `AuctionRules::SNAPSHOT_VERSION`. Past
 * auctions hold version 2 with the rate key present; this migration rewrites
 * those rows to version 3 with the key gone, so the engine (which refuses any
 * version but the current one) keeps reading them. This is the sanctioned
 * moment to touch a frozen snapshot: it is exactly the schema evolution the
 * trigger exists to keep honest, so the trigger is dropped for the duration
 * and restored verbatim afterwards.
 *
 * SNAPSHOT VERSION 1 IS NOT TOUCHED. That shape described the long-abandoned
 * last-bidder model and is refused rather than reinterpreted. If one exists,
 * this migration stops and asks a human, exactly as the engine would.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // The ruleset column.
        // ---------------------------------------------------------------

        DB::statement('ALTER TABLE auction_rulesets DROP CONSTRAINT chk_rulesets_discount_rate');

        Schema::table('auction_rulesets', function (Blueprint $table) {
            $table->dropColumn('buy_now_credit_discount_minor_per_credit');
        });

        // ---------------------------------------------------------------
        // The written snapshots. Guard, then migrate, then re-freeze.
        // ---------------------------------------------------------------

        // Any row that is not a version-2 snapshot is refused or already on
        // version 3. In either case it must not be silently rewritten by a
        // migration -- version 1 is the forbidden last-bidder shape, an absent
        // version is unreadable by the engine, and version 3 needs no change.
        // Rather than guess which, stop on any of them.
        $versions = DB::table('auctions')
            ->select('rules_snapshot')
            ->get()
            ->map(fn ($row): int => (int) (
                json_decode($row->rules_snapshot, true)['rules']['snapshot_version'] ?? 0
            ))
            ->unique()
            ->values()
            ->all();

        if ($versions !== [] && array_diff($versions, [2, 3]) !== []) {
            throw new RuntimeException(
                'Cannot migrate auction snapshots: found rules snapshot version(s) '
                .implode(', ', array_map('strval', $versions))
                .' in the database. Only version 2 (the corrected highest-bid shape) can be '
                .'migrated to version 3; version 1 is refused rather than reinterpreted. '
                .'Inspect these rows before continuing.'
            );
        }

        DB::unprepared('DROP TRIGGER IF EXISTS auctions_frozen_configuration');

        // Version 2 -> 3: strip the flat rate and record the new version.
        DB::statement(<<<'SQL'
            UPDATE auctions
            SET rules_snapshot = JSON_SET(
                JSON_REMOVE(rules_snapshot, '$.rules.buy_now_credit_discount_minor_per_credit'),
                '$.rules.snapshot_version', 3
            )
            WHERE CAST(
                JSON_UNQUOTE(JSON_EXTRACT(rules_snapshot, '$.rules.snapshot_version'))
                AS UNSIGNED
            ) = 2
        SQL);

        // Restored verbatim from the original creation migration.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER auctions_frozen_configuration
            BEFORE UPDATE ON auctions
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    IF NOT (NEW.rules_snapshot <=> OLD.rules_snapshot)
                        OR NOT (NEW.snapshot_version <=> OLD.snapshot_version)
                        OR NOT (NEW.settlement_amount_minor <=> OLD.settlement_amount_minor)
                        OR NOT (NEW.product_id <=> OLD.product_id)
                        OR NOT (NEW.currency <=> OLD.currency)
                    THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'An auction configuration is frozen once the auction leaves draft.';
                    END IF;
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        // The schema comes back; the data cannot. The flat rate removed from
        // written snapshots is gone, because the old model did not store how
        // each past auction arrived at its rate -- there was one rate for
        // everything. A down-migration cannot invent one per auction, and must
        // not finish with snapshots that lie, so it does not attempt to.
        Schema::table('auction_rulesets', function (Blueprint $table) {
            $table->unsignedBigInteger('buy_now_credit_discount_minor_per_credit')
                ->default(100)
                ->after('buy_now_credit_discount_enabled');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auction_rulesets
            ADD CONSTRAINT chk_rulesets_discount_rate CHECK (
                buy_now_credit_discount_minor_per_credit > 0
            )
        SQL);
    }
};

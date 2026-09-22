<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for pot-target bidding: docs/PLAN_POT_TARGET_BIDDING.md, step 3.
 *
 * Adds the per-auction target the closing sweep will compare against the
 * running sum of accepted bids (D-2, D-10), parallel to
 * `settlement_amount_minor` in every respect: an admin-entered figure, frozen
 * once the auction leaves Draft, never derived from the product and never
 * part of `rules_snapshot` (D-4, D-8) -- two auctions on the same product may
 * reasonably carry different targets, the same reason settlement itself is
 * per-auction.
 *
 * **Denominated in Credits, not GH₵ (D-10).** Stored as subcredits, exactly
 * like `bids.amount_credits` and every other post-redenomination credit
 * column, so the pot (a sum of `amount_credits`) and the target it is
 * compared against are the same unit with no conversion in between. There is
 * no rate anywhere in this codebase that turns credits into money except the
 * one sanctioned per-lot valuation, and that valuation is a per-user
 * calculation -- never an aggregate summed across an auction's bidders -- so
 * it has no part in this comparison.
 *
 * **Nullable, deliberately.** Nothing yet sets this column: step 6 gives the
 * admin form a field for it, and until then every auction (existing and
 * newly created) simply has no target, which is exactly the "never reached"
 * behaviour §2a already describes -- the auction closes on its clock alone.
 * A null target is not a placeholder value; it is the honest state of "this
 * auction has no second way to close," and CreateAuction does not need to
 * change in this step to remain correct.
 *
 * **The new closure reason.** `highest_bid` already means, and has always
 * meant, "closed on the clock; the highest valid credit bid won" -- every
 * auction that has ever closed that way on staging recorded exactly this
 * value, and its meaning is left untouched for their sake (never reinterpret
 * a recorded value, the same discipline the snapshot rule holds for
 * `rules_snapshot` keys). `pot_target_reached` is new, and distinguishes the
 * other of D-6's two ways to close normally. Nothing writes it yet -- that is
 * step 4's engine work -- so widening the CHECK constraint here is exactly as
 * inert today as adding `buy_now` once was, before Buy Now termination was
 * wired up.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS auctions_frozen_configuration');

        Schema::table('auctions', function (Blueprint $table) {
            // Positioned beside settlement_amount_minor: the two are the
            // auction's own economics, read together by anyone reviewing why
            // it closed when it did and what the winner owes.
            $table->unsignedBigInteger('pot_target_credits')->nullable()->after('settlement_amount_minor');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auctions
            ADD CONSTRAINT chk_auctions_pot_target_positive CHECK (
                pot_target_credits IS NULL OR pot_target_credits > 0
            )
        SQL);

        DB::statement('ALTER TABLE auctions DROP CONSTRAINT chk_auctions_closure_reason');

        DB::statement(<<<'SQL'
            ALTER TABLE auctions
            ADD CONSTRAINT chk_auctions_closure_reason CHECK (
                closure_reason IS NULL OR closure_reason IN (
                    'highest_bid','buy_now','no_bids','cancelled','forfeited','pot_target_reached'
                )
            )
        SQL);

        // Re-created with pot_target_credits added to the guarded list, same
        // shape as every other column here -- copied verbatim from the
        // migration that most recently defined this trigger
        // (2026_09_22_100000_redenominate_credits_to_subcredits.php) with
        // exactly one line added.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER auctions_frozen_configuration
            BEFORE UPDATE ON auctions
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    IF NOT (NEW.rules_snapshot <=> OLD.rules_snapshot)
                        OR NOT (NEW.snapshot_version <=> OLD.snapshot_version)
                        OR NOT (NEW.settlement_amount_minor <=> OLD.settlement_amount_minor)
                        OR NOT (NEW.pot_target_credits <=> OLD.pot_target_credits)
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
        DB::unprepared('DROP TRIGGER IF EXISTS auctions_frozen_configuration');

        DB::statement('ALTER TABLE auctions DROP CONSTRAINT chk_auctions_closure_reason');

        DB::statement(<<<'SQL'
            ALTER TABLE auctions
            ADD CONSTRAINT chk_auctions_closure_reason CHECK (
                closure_reason IS NULL OR closure_reason IN (
                    'highest_bid','buy_now','no_bids','cancelled','forfeited'
                )
            )
        SQL);

        DB::statement('ALTER TABLE auctions DROP CONSTRAINT chk_auctions_pot_target_positive');

        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn('pot_target_credits');
        });

        // Restored to its pre-migration shape, verbatim.
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
};

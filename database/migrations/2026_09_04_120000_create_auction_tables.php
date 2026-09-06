<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The auction engine: auction instances, their bids, and their state history.
 *
 * THREE DIFFERENT NUMBERS. The schema keeps them in three different places
 * and never derives one from another:
 *
 *   products.buy_now_price_minor          GH 5,500.00  what buying it outright costs
 *   auctions.settlement_amount_minor      GH   100.00  what a normal winner pays
 *   bids.amount_credits                          150   credits committed to a bid
 *
 * The first two are pesewas. The third is a count of credits and is not money
 * at all -- 150 credits is not GH 150, and nothing in this schema converts
 * between them. The single exception is the Buy Now discount rate carried in
 * the frozen snapshot, which applies only to the Buy Now price.
 *
 * IMMUTABILITY. An auction is a historical instance, not a live view of
 * configuration. Its snapshot and its economics are frozen once it leaves
 * Draft, enforced here by a trigger as well as in the model, so an
 * administrator editing a ruleset tomorrow cannot change what an auction
 * running today was created under.
 *
 * All timestamps are UTC. Ghana time is a presentation concern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();

            // Restricted: a product with auction history cannot be deleted out
            // from under it. Archive the product instead.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Lineage only. The engine never reads this row -- it reads the
            // frozen snapshot below. Nullable so a ruleset deleted years later
            // cannot take the auction's history with it.
            $table->foreignId('auction_ruleset_id')->nullable()
                ->constrained('auction_rulesets')->nullOnDelete();

            // ---- Frozen configuration ------------------------------------
            //
            // The complete rules the auction runs under, plus its own
            // economics. Written once, at creation, and unchangeable
            // thereafter once published.
            $table->json('rules_snapshot');
            $table->unsignedInteger('snapshot_version');

            // What a normal highest-bid winner pays, in integer pesewas.
            // Deliberately independent of the product's Buy Now price: two
            // auctions on the same product may settle at GH 50 and GH 150.
            // Named for what it is -- not an auction price, not a bid price,
            // and never the value of anyone's credits.
            $table->unsignedBigInteger('settlement_amount_minor');
            $table->char('currency', 3)->default('GHS');

            $table->string('status', 30)->default('draft');
            $table->string('closure_reason', 30)->nullable();

            // ---- The clock -----------------------------------------------
            //
            // These timestamps are the auction's only authority on time. No
            // browser clock, JavaScript timer or PHP process lifetime has any
            // bearing on when an auction ends.
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('closing_started_at')->nullable();

            // Extension accounting, so both ruleset limits can be enforced
            // without replaying the bid history.
            $table->unsignedInteger('extensions_applied')->default(0);
            $table->unsignedInteger('extension_seconds_applied')->default(0);

            // ---- Highest bid projection ----------------------------------
            //
            // A cache of the authoritative query, for listing pages that would
            // otherwise aggregate the bid table on every row. Written only by
            // the resolver, always from the bid records, and fully rebuildable
            // from them. The bids are the source of truth; these are not.
            $table->foreignId('highest_bid_id')->nullable();
            $table->unsignedBigInteger('highest_bid_credits')->nullable();
            $table->unsignedBigInteger('bid_count')->default(0);

            // ---- Normal closure ------------------------------------------
            //
            // The highest valid credit bidder. Null unless the auction closed
            // on the clock with at least one bid.
            //
            // Restricted rather than nulled on delete, for two reasons. A
            // winner who cannot be identified is not a record of anything. And
            // MySQL refuses a CHECK constraint on a column whose foreign key
            // carries a referential action -- the pairing constraints below
            // are worth more than the convenience of a cascading delete.
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('winning_bid_id')->nullable();
            $table->timestamp('settlement_due_at')->nullable();
            $table->timestamp('settled_at')->nullable();

            // ---- Buy Now termination -------------------------------------
            //
            // A separate person in a separate column. A Buy Now buyer is not
            // the auction's winner, and merging the two would make the record
            // of what happened ambiguous.
            $table->timestamp('buy_now_ended_at')->nullable();
            $table->foreignId('buy_now_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // What the Buy Now buyer was actually charged, kept as evidence:
            // the credits that qualified, the discount they earned, and the
            // amount payable after it.
            $table->unsignedBigInteger('buy_now_eligible_credits')->nullable();
            $table->unsignedBigInteger('buy_now_discount_minor')->nullable();
            $table->unsignedBigInteger('buy_now_payable_minor')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('forfeited_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The clock sweep: auctions due to start, and due to end.
            $table->index(['status', 'scheduled_start_at']);
            $table->index(['status', 'ends_at']);
            // Public and admin listings.
            $table->index(['status', 'created_at']);
            $table->index(['product_id', 'status']);
            // Settlement work queues.
            $table->index(['status', 'settlement_due_at']);
            $table->index('winner_user_id');
            $table->index('buy_now_user_id');
            $table->index('closure_reason');
        });

        Schema::create('bids', function (Blueprint $table) {
            $table->id();

            // Restricted on both sides. A bid is a financial record: it
            // consumed credits that are gone. Deleting the auction or the
            // bidder would destroy the evidence of that.
            $table->foreignId('auction_id')->constrained('auctions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // The credits this bid committed. A count, not money, and variable
            // per bid -- 20, 50, 100, 150. There is no fixed cost per bid
            // anywhere in this schema, and one bid never means one credit.
            $table->unsignedBigInteger('amount_credits');

            // Position within the auction, allocated under the auction row
            // lock. Gives the highest-bid query a deterministic tie-break that
            // does not depend on timestamp resolution: two bids of equal
            // amount are separated by which was accepted first.
            $table->unsignedBigInteger('sequence');

            $table->string('status', 20)->default('accepted');

            // The audit chain, and the reason this column is NOT NULL: a bid
            // cannot exist without the credit consumption that paid for it.
            // Through it, an auditor reaches the lot consumptions and can
            // answer exactly which credits a user spent on this auction.
            $table->foreignId('credit_transaction_id')
                ->constrained('credit_transactions')->restrictOnDelete();

            // One logical bid, however many times the request is retried.
            $table->string('idempotency_key', 191)->nullable();

            // Created only -- a bid is never updated -- and with millisecond
            // precision, because the minimum bid interval is expressed in
            // milliseconds and a whole-second column could not enforce it.
            $table->timestamp('created_at', 3)->useCurrent();

            // The highest-bid lookup, tie-break included.
            //
            // This ascending shape does NOT serve the resolver's
            // `amount_credits DESC, sequence ASC` ordering -- one index scan
            // has one direction, and a mixed ordering needs matching column
            // directions. The original comment here claimed otherwise and was
            // wrong; `2026_09_08_120000_correct_highest_bid_index_direction`
            // replaces this index with the descending form and records the
            // measurement that proved it. Left as written so the correction
            // reads as a correction.
            $table->index(['auction_id', 'amount_credits', 'sequence'], 'bids_highest_bid_index');
            // Bid history, newest first.
            $table->index(['auction_id', 'created_at']);
            // A user's own bids, and the eligible-credit sum behind their
            // Buy Now discount.
            $table->index(['user_id', 'auction_id']);

            $table->unique(['auction_id', 'sequence']);
            $table->unique('idempotency_key');
            // One bid per credit consumption, in both directions.
            $table->unique('credit_transaction_id');
        });

        // Every lifecycle change, kept as evidence. When a bidder asks why an
        // auction ended when it did, this is the record that answers.
        Schema::create('auction_transitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();

            $table->foreignId('caused_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auction_id', 'created_at']);
        });

        // Added after both tables exist, since they point at each other.
        Schema::table('auctions', function (Blueprint $table) {
            // Restricted. Bids are append-only and cannot be deleted anyway,
            // and a referential action here would block the CHECK constraints
            // that keep a winner and their winning bid together.
            $table->foreign('highest_bid_id')->references('id')->on('bids');
            $table->foreign('winning_bid_id')->references('id')->on('bids');
        });

        // ---------------------------------------------------------------
        // Constraints. Every one of these is also enforced in the domain
        // services. They are repeated here because a service can be bypassed
        // by a console command or a hand-run statement, and a financial
        // invariant that lives only in application code is a convention
        // rather than a guarantee.
        // ---------------------------------------------------------------

        DB::statement(<<<'SQL'
            ALTER TABLE auctions
            ADD CONSTRAINT chk_auctions_status CHECK (
                status IN (
                    'draft','scheduled','live','closing','pending_settlement',
                    'settled','unsold','cancelled','forfeited','relisted'
                )
            ),
            ADD CONSTRAINT chk_auctions_closure_reason CHECK (
                closure_reason IS NULL OR closure_reason IN (
                    'highest_bid','buy_now','no_bids','cancelled','forfeited'
                )
            ),
            ADD CONSTRAINT chk_auctions_settlement_positive CHECK (settlement_amount_minor > 0),
            ADD CONSTRAINT chk_auctions_highest_bid_positive CHECK (
                highest_bid_credits IS NULL OR highest_bid_credits > 0
            ),
            ADD CONSTRAINT chk_auctions_ends_after_start CHECK (
                starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at
            )
        SQL);

        // A winner and the bid they won with arrive together or not at all.
        // Half of that pair would leave the auction unable to say what was won
        // or on what terms.
        DB::statement(<<<'SQL'
            ALTER TABLE auctions
            ADD CONSTRAINT chk_auctions_winner_pairing CHECK (
                (winner_user_id IS NULL AND winning_bid_id IS NULL)
                OR (winner_user_id IS NOT NULL AND winning_bid_id IS NOT NULL)
            )
        SQL);

        // A Buy Now ending is fully recorded or not recorded at all.
        DB::statement(<<<'SQL'
            ALTER TABLE auctions
            ADD CONSTRAINT chk_auctions_buy_now_pairing CHECK (
                (buy_now_ended_at IS NULL AND buy_now_user_id IS NULL)
                OR (buy_now_ended_at IS NOT NULL AND buy_now_user_id IS NOT NULL)
            )
        SQL);

        // The rule from the brief, in the schema: a Buy Now ending never has a
        // highest-bid winner attached. The standing highest bidder does not
        // win when someone buys the product outright, and the database will
        // not store a row that claims both.
        DB::statement(<<<'SQL'
            ALTER TABLE auctions
            ADD CONSTRAINT chk_auctions_buy_now_has_no_bid_winner CHECK (
                buy_now_user_id IS NULL OR winner_user_id IS NULL
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bids
            ADD CONSTRAINT chk_bids_amount_positive CHECK (amount_credits > 0),
            ADD CONSTRAINT chk_bids_status CHECK (status IN ('accepted'))
        SQL);

        // The bid table is append-only, for the same reason the credit ledger
        // is: a bid that can be edited afterwards is not evidence of what
        // anyone committed, and its amount decides who wins.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bids_no_update
            BEFORE UPDATE ON bids
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Bids are append-only: a bid cannot be edited once accepted.';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bids_no_delete
            BEFORE DELETE ON bids
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Bids are append-only: the credits behind a bid are permanently consumed.';
            END
        SQL);

        // The frozen snapshot, enforced below the application.
        //
        // Draft is the only state in which configuration may change. After
        // publication the rules, the settlement amount, the product and the
        // currency are fixed -- so a running auction cannot be quietly
        // rewritten, and a closed one still explains itself.
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

        // Lifecycle history is evidence too.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER auction_transitions_no_update
            BEFORE UPDATE ON auction_transitions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Auction history is append-only.';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS auction_transitions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS auctions_frozen_configuration');
        DB::unprepared('DROP TRIGGER IF EXISTS bids_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS bids_no_update');

        Schema::table('auctions', function (Blueprint $table) {
            $table->dropForeign(['highest_bid_id']);
            $table->dropForeign(['winning_bid_id']);
        });

        Schema::dropIfExists('auction_transitions');
        Schema::dropIfExists('bids');
        Schema::dropIfExists('auctions');
    }
};

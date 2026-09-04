<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders, their items, the attempts to pay for them, and their history.
 *
 * WHAT AN ORDER IS. A purchase obligation in GHS, frozen at checkout. It is
 * not an auction, not a payment and not an inventory movement -- each of those
 * owns its own table and its own lifecycle, and an order merely relates to
 * them.
 *
 * MONEY ONLY. Every amount here is integer pesewas. There is deliberately no
 * credit column anywhere in these tables: credits are the bidding mechanism
 * and are consumed permanently at bid time, never charged again at checkout.
 * The one place the two meet is `discount_minor` on a Buy Now order, which
 * records the cedis that consumed bid credits took off a Buy Now price -- the
 * credits themselves stay consumed, and the count that earned the discount is
 * kept alongside it purely as evidence.
 *
 * THE COMPONENTS STAY SEPARATE, so a total can always be explained:
 *
 *     subtotal - discount + delivery + tax = total
 *
 * A CHECK constraint enforces exactly that. Delivery is never folded into a
 * product price, and a discount never into a delivery charge.
 *
 * FROZEN AT CHECKOUT. Every figure is written once, from server-side reads,
 * before the provider is contacted. Nothing is recalculated later from a
 * product or ruleset that may have moved on, and once a payment is verified a
 * trigger refuses to let any of it change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Human-readable and stable, so support never has to quote a
            // database id to a customer.
            $table->string('order_number', 32)->unique();

            // Restricted: an order is a financial record, and deleting the
            // customer would destroy the evidence of what they bought.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // How this order came about, stated rather than inferred from
            // which columns happen to be filled in.
            $table->string('source', 20);
            $table->string('status', 30)->default('pending_payment');

            // ---- The auction relationship --------------------------------
            //
            // Nullable, because a Buy Now order need not involve one. When it
            // is set on a Buy Now order it means this purchase ended that
            // auction; on a settlement order it means this is what the winner
            // owes.
            $table->foreignId('auction_id')->nullable()
                ->constrained('auctions')->restrictOnDelete();

            // The bid that won, for a settlement order. Evidence of what the
            // winner won with -- never of what they pay, which is the
            // auction's own settlement amount.
            $table->foreignId('winning_bid_id')->nullable()
                ->constrained('bids')->restrictOnDelete();

            // ---- The frozen commercial figures ---------------------------
            $table->char('currency', 3)->default('GHS');

            // What the goods cost before anything is applied: a product's Buy
            // Now price, or an auction's settlement amount.
            $table->unsignedBigInteger('subtotal_minor');

            // Cedis taken off by consumed bid credits. Zero on a settlement
            // order -- a winner's credits earn no reduction of settlement.
            $table->unsignedBigInteger('discount_minor')->default(0);

            $table->unsignedBigInteger('delivery_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');

            // Evidence for the discount, not an amount: the number of credits
            // this buyer had consumed bidding on this auction when the
            // checkout was frozen. A count, never money.
            $table->unsignedBigInteger('discount_credits')->default(0);

            // The complete pricing derivation as it stood at checkout,
            // including the rate the discount was computed at. Kept whole so a
            // disputed total can be re-explained without re-deriving it from
            // values that have since changed.
            $table->json('pricing_snapshot');

            // ---- Inventory -----------------------------------------------
            //
            // Whether this order is itself holding a unit aside. False for an
            // auction-linked order: the auction already reserved one, and a
            // second reservation would take two units off the shelf for one
            // sale. Explicit rather than derived, because releasing a
            // reservation nobody took would overstate available stock.
            $table->boolean('holds_reservation')->default(false);

            // ---- The clock -----------------------------------------------
            //
            // When this checkout stops being payable. Server time, always.
            // Its purpose is concrete: an abandoned checkout must not hold
            // stock indefinitely.
            $table->timestamp('payment_due_at')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();

            // Set when a payment succeeded but the order could not be
            // completed -- an auction that closed while the buyer was paying,
            // for instance. The money is real and recorded; this says why
            // nothing further happened, so it lands in a queue for a human
            // rather than being silently marked done or silently lost.
            $table->string('fulfilment_blocked_reason', 500)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['source', 'status']);
            // The expiry sweep.
            $table->index(['status', 'payment_due_at']);
            $table->index('auction_id');
            // The operations queue for paid orders that could not complete.
            $table->index('fulfilment_blocked_reason');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Restricted: a product that has been ordered cannot be deleted
            // out from under its order history.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Snapshots. An order from last year must still say what was
            // bought and what it cost, whatever the product record says now --
            // products get renamed, re-SKU'd and repriced.
            $table->string('product_name_snapshot', 200);
            $table->string('sku_snapshot', 64);

            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('line_total_minor');

            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('order_id');
            $table->index('product_id');
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();

            $table->string('provider', 30)->default('paystack');

            // Our reference, generated server-side and sent to the provider.
            // Unique, so a provider event maps to exactly one attempt.
            $table->string('provider_reference', 64)->unique();

            // What was actually opened with the provider. Kept here rather
            // than read back off the order, so verification compares against
            // the figure the provider was genuinely asked for.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');

            $table->string('status', 20)->default('initiated');

            // What the provider handed back when the transaction was opened.
            // Not a credential: the authorization URL is where to send the
            // payer, and the access code is scoped to this transaction.
            $table->string('authorization_url', 500)->nullable();
            $table->string('access_code', 100)->nullable();

            // The provider's own transaction id. Unique, so the same provider
            // transaction cannot be recorded against two attempts.
            $table->unsignedBigInteger('provider_transaction_id')->nullable()->unique();
            $table->string('provider_channel', 40)->nullable();

            // The key fulfilment runs under, so repeated webhooks, callbacks
            // and retries converge on one financial effect.
            $table->string('idempotency_key', 191)->unique();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Every state change, kept as evidence. When a customer disputes what
        // happened to an order, this is the record that answers.
        Schema::create('order_transitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();

            $table->foreignId('caused_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        // A webhook may now resolve to an order payment as well as to a credit
        // purchase. Two nullable references rather than a polymorphic one: an
        // event resolves to exactly one of them, and naming both makes the
        // foreign keys real.
        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->foreignId('order_payment_id')->nullable()->after('credit_purchase_id')
                ->constrained('order_payments')->nullOnDelete();
        });

        // ---------------------------------------------------------------
        // Constraints. Repeated below the application because a service can
        // be bypassed by a console command or a hand-run statement, and a
        // financial invariant that lives only in application code is a
        // convention rather than a guarantee.
        // ---------------------------------------------------------------

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_source CHECK (source IN ('buy_now','auction_win')),
            ADD CONSTRAINT chk_orders_status CHECK (
                status IN (
                    'pending_payment','paid','processing','fulfilled',
                    'cancelled','payment_failed','payment_expired'
                )
            ),
            ADD CONSTRAINT chk_orders_total_positive CHECK (total_minor > 0),
            ADD CONSTRAINT chk_orders_subtotal_positive CHECK (subtotal_minor > 0)
        SQL);

        // The total has to be the sum of its parts. Without this a component
        // could be adjusted while the total stayed put, and the order would
        // no longer explain itself.
        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_total_consistent CHECK (
                total_minor = subtotal_minor - discount_minor + delivery_minor + tax_minor
            ),
            ADD CONSTRAINT chk_orders_discount_within_subtotal CHECK (
                discount_minor <= subtotal_minor
            )
        SQL);

        // A settlement order must name the auction it settles and the bid that
        // won it. Half of that pair would leave the order unable to say what
        // it is for.
        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_auction_win_pairing CHECK (
                source <> 'auction_win'
                OR (auction_id IS NOT NULL AND winning_bid_id IS NOT NULL)
            )
        SQL);

        // A winner's consumed credits earn no reduction of what they settle.
        // The discount exists only on the Buy Now path.
        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_no_settlement_discount CHECK (
                source <> 'auction_win' OR (discount_minor = 0 AND discount_credits = 0)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT chk_order_items_quantity_positive CHECK (quantity > 0),
            ADD CONSTRAINT chk_order_items_price_positive CHECK (unit_price_minor > 0),
            ADD CONSTRAINT chk_order_items_line_total CHECK (
                line_total_minor = (unit_price_minor * quantity) - discount_minor
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE order_payments
            ADD CONSTRAINT chk_order_payments_amount_positive CHECK (amount_minor > 0),
            ADD CONSTRAINT chk_order_payments_status CHECK (
                status IN ('initiated','pending','success','failed','abandoned')
            )
        SQL);

        // Order history is evidence.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER order_transitions_no_update
            BEFORE UPDATE ON order_transitions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Order history is append-only.';
            END
        SQL);

        // So are the items on a paid order.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER order_items_no_update
            BEFORE UPDATE ON order_items
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Order items are written once at checkout and never edited.';
            END
        SQL);

        // The commercial record, frozen the moment money is verified.
        //
        // Before payment an order may still be corrected -- nobody has paid
        // anything. Afterwards these figures describe a transaction that
        // happened, and a correction is a separate explicit financial act
        // rather than a rewrite of the original.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER orders_frozen_after_payment
            BEFORE UPDATE ON orders
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('paid','processing','fulfilled') THEN
                    IF NOT (NEW.subtotal_minor <=> OLD.subtotal_minor)
                        OR NOT (NEW.discount_minor <=> OLD.discount_minor)
                        OR NOT (NEW.discount_credits <=> OLD.discount_credits)
                        OR NOT (NEW.delivery_minor <=> OLD.delivery_minor)
                        OR NOT (NEW.tax_minor <=> OLD.tax_minor)
                        OR NOT (NEW.total_minor <=> OLD.total_minor)
                        OR NOT (NEW.currency <=> OLD.currency)
                        OR NOT (NEW.pricing_snapshot <=> OLD.pricing_snapshot)
                        OR NOT (NEW.source <=> OLD.source)
                        OR NOT (NEW.auction_id <=> OLD.auction_id)
                        OR NOT (NEW.winning_bid_id <=> OLD.winning_bid_id)
                        OR NOT (NEW.user_id <=> OLD.user_id)
                    THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'A paid order is a historical record: its commercial figures cannot be changed.';
                    END IF;
                END IF;
            END
        SQL);

        // What was asked of the provider cannot be rewritten to match what
        // came back. Verification compares the provider's answer against this
        // row, so a mutable amount would make that check meaningless.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER order_payments_frozen_request
            BEFORE UPDATE ON order_payments
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.amount_minor <=> OLD.amount_minor)
                    OR NOT (NEW.currency <=> OLD.currency)
                    OR NOT (NEW.provider_reference <=> OLD.provider_reference)
                    OR NOT (NEW.order_id <=> OLD.order_id)
                THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A payment attempt records what was asked of the provider and cannot be changed.';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS order_payments_frozen_request');
        DB::unprepared('DROP TRIGGER IF EXISTS orders_frozen_after_payment');
        DB::unprepared('DROP TRIGGER IF EXISTS order_items_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS order_transitions_no_update');

        Schema::table('payment_webhook_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_payment_id');
        });

        Schema::dropIfExists('order_transitions');
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store Wallet takes part in a fixed-price catalogue checkout.
 *
 * An order gains two figures:
 *
 *     store_wallet_applied_minor   value committed from a Store Wallet
 *     payable_minor                total - applied: what the provider is asked
 *                                  for and what the customer still owes in cash
 *
 * The payable is what `order_payments` must charge and what verification
 * compares against -- never the total, which has an applied portion that no
 * provider was ever asked to cover. The frozen-by-payment trigger therefore
 * guards both columns, and `FulfillOrderPayment` verifies against the frozen
 * payable rather than a figure that could have moved.
 *
 * A CHECK constraint keeps the two honest with the total that already exists:
 *
 *     payable = total - applied,  payable > 0,  applied <= total
 *
 * `payable > 0` is the stage boundary stated as a constraint -- every order
 * still has a provider-chargeable remainder, because there is deliberately no
 * path to Paid without a provider verifying anything.
 *
 * WHERE APPLYED MAY BE NON-ZERO. Only on a fixed-price catalogue purchase --
 * source `buy_now` with no auction. The settlement path and the auction Buy
 * Now path each run their own economics, and the database refuses an order
 * that claims otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('store_wallet_applied_minor')->default(0)
                ->after('discount_credits');

            $table->unsignedBigInteger('payable_minor')->default(0)
                ->after('store_wallet_applied_minor');
        });

        // Existing orders predate the Store Wallet: none of them applied any,
        // so every one of them owes its full total. Backfilled before the
        // CHECKs below exist, so the constraint never sees a transition.
        DB::statement('UPDATE orders SET payable_minor = total_minor');

        // The payable is derived from the frozen total and the applied value,
        // and may not be written independently of either. It is what tells a
        // confused reader -- and a future developer -- what a provider actually
        // received a verification for.
        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_payable_positive CHECK (payable_minor > 0),
            ADD CONSTRAINT chk_orders_payable_consistent CHECK (
                payable_minor = total_minor - store_wallet_applied_minor
            ),
            ADD CONSTRAINT chk_orders_store_wallet_within_total CHECK (
                store_wallet_applied_minor <= total_minor
            ),
            ADD CONSTRAINT chk_orders_store_wallet_buy_now_only CHECK (
                store_wallet_applied_minor = 0
                OR (source = 'buy_now' AND auction_id IS NULL)
            )
        SQL);

        // The freeze now covers the applied value and the payable, exactly as
        // it covers every other commercial figure. Rebuilt from the current
        // shape (which the refunds stage extended with 'refunded') plus the
        // two new columns, so a paid order cannot have its economics amended
        // through a Store Wallet side door.
        DB::unprepared('DROP TRIGGER IF EXISTS orders_frozen_after_payment');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER orders_frozen_after_payment
            BEFORE UPDATE ON orders
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('paid','processing','fulfilled','refunded') THEN
                    IF NOT (NEW.subtotal_minor <=> OLD.subtotal_minor)
                        OR NOT (NEW.discount_minor <=> OLD.discount_minor)
                        OR NOT (NEW.discount_credits <=> OLD.discount_credits)
                        OR NOT (NEW.store_wallet_applied_minor <=> OLD.store_wallet_applied_minor)
                        OR NOT (NEW.payable_minor <=> OLD.payable_minor)
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
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS orders_frozen_after_payment');

        DB::statement('ALTER TABLE orders DROP CHECK chk_orders_payable_positive');
        DB::statement('ALTER TABLE orders DROP CHECK chk_orders_payable_consistent');
        DB::statement('ALTER TABLE orders DROP CHECK chk_orders_store_wallet_within_total');
        DB::statement('ALTER TABLE orders DROP CHECK chk_orders_store_wallet_buy_now_only');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['store_wallet_applied_minor', 'payable_minor']);
        });

        // Restored to the shape the refunds stage left behind.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER orders_frozen_after_payment
            BEFORE UPDATE ON orders
            FOR EACH ROW
            BEGIN
                IF OLD.status IN ('paid','processing','fulfilled','refunded') THEN
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
    }
};

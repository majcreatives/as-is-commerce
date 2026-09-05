<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds: money the platform received and gave back.
 *
 * A REFUND IS A SEPARATE FINANCIAL EVENT, NOT AN EDIT. It points at the
 * payment it returns and never touches it. After a full refund the original
 * `order_payments` row still reads `success` for its full amount, because that
 * is what happened -- the platform was paid, and then it paid back. Two
 * questions, two answers, two records:
 *
 *     What was originally paid?   order_payments.amount_minor
 *     How much was refunded?      SUM(refunds.amount_minor) WHERE succeeded
 *
 * Rewriting the payment instead would destroy the first answer to store the
 * second, and a customer's receipt would stop agreeing with our records.
 *
 * GHS ONLY. Every amount here is integer pesewas, like every other amount in
 * this application. There is deliberately no credit column: auction bid
 * credits are consumed permanently when a bid is accepted, and no refund of
 * money returns any of them. Nothing in this table can express one.
 *
 * NOTHING REACHES `succeeded` WITHOUT THE PROVIDER SAYING SO. The row is
 * written `pending` before Paystack is contacted, so a failed call leaves an
 * attempt on record rather than vanishing with a rolled-back transaction.
 * Paystack settles refunds asynchronously, so `processing` is the honest state
 * for as long as it is still deciding, and `refunds:reconcile` is what turns
 * that into an outcome.
 *
 * THE ORDER STATUS LIST GAINS `refunded`, and the freeze trigger gains it too.
 * Both are altered here rather than in the Stage 7 migration, which describes
 * what Stage 7 built and should keep describing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            // Restricted, both of them. A refund is financial evidence, and
            // deleting the order or the payment it refers to would leave money
            // recorded as returned with nothing saying what it was returned
            // against.
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_payment_id')->constrained('order_payments')->restrictOnDelete();

            $table->string('provider', 30)->default('paystack');

            // The provider's own id for this refund, once it has given us one.
            // Nullable because the row exists before the call is made, and
            // unique because one provider refund is one refund here.
            $table->string('provider_reference', 100)->nullable()->unique();

            // The provider's own word for where the refund has got to, kept
            // verbatim. Reconciliation compares our interpretation against
            // this rather than against a status we derived and then forgot the
            // basis of.
            $table->string('provider_status', 40)->nullable();

            // What is being returned. Integer minor units, never a float, and
            // frozen by the trigger below: an amount that could be edited
            // after the fact would make every over-refund check meaningless.
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');

            $table->string('status', 20)->default('pending');

            // Why, as a controlled code plus an optional human note. The code
            // is what this is filed under and counted by; the note is detail.
            // Neither changes what happens to the money.
            $table->string('reason', 40);
            $table->string('note', 500)->nullable();

            // Who asked for it. Restricted: an administrator who leaves the
            // business must not take the record of their decision with them.
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();

            // One request, one refund, however many times the button is
            // pressed or the request is replayed.
            $table->string('idempotency_key', 191)->unique();

            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Safe to show staff: the provider's rejection message or a
            // transport failure, never a payload and never a credential.
            $table->string('failure_reason', 500)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            // The refundable-amount calculation, which sums by payment.
            $table->index(['order_payment_id', 'status']);
            // The administrative queue and its filters.
            $table->index(['status', 'created_at']);
            $table->index('reason');
        });

        // ---------------------------------------------------------------
        // Constraints. Repeated below the application, because a service can
        // be bypassed by a console command or a hand-run statement, and a
        // financial invariant that lives only in application code is a
        // convention rather than a guarantee.
        // ---------------------------------------------------------------

        DB::statement(<<<'SQL'
            ALTER TABLE refunds
            ADD CONSTRAINT chk_refunds_amount_positive CHECK (amount_minor > 0),
            ADD CONSTRAINT chk_refunds_status CHECK (
                status IN ('pending','processing','succeeded','failed')
            ),
            ADD CONSTRAINT chk_refunds_reason CHECK (
                reason IN (
                    'inventory_conflict','payment_after_checkout_expiry',
                    'payment_after_cancellation','duplicate_payment',
                    'administrative_recovery','other'
                )
            )
        SQL);

        // A succeeded refund must say when, and a failed one must say why.
        // Without this a row could claim an outcome it has no evidence for.
        DB::statement(<<<'SQL'
            ALTER TABLE refunds
            ADD CONSTRAINT chk_refunds_succeeded_has_time CHECK (
                status <> 'succeeded' OR succeeded_at IS NOT NULL
            ),
            ADD CONSTRAINT chk_refunds_failed_has_reason CHECK (
                status <> 'failed' OR failure_reason IS NOT NULL
            )
        SQL);

        // What was asked of the provider, and against what, cannot be
        // rewritten afterwards. The amount especially: every over-refund check
        // in the application sums this column, so an editable amount would
        // turn the cap into a suggestion.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER refunds_frozen_request
            BEFORE UPDATE ON refunds
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.amount_minor <=> OLD.amount_minor)
                    OR NOT (NEW.currency <=> OLD.currency)
                    OR NOT (NEW.order_id <=> OLD.order_id)
                    OR NOT (NEW.order_payment_id <=> OLD.order_payment_id)
                    OR NOT (NEW.requested_by <=> OLD.requested_by)
                    OR NOT (NEW.idempotency_key <=> OLD.idempotency_key)
                THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A refund records what was asked of the provider and cannot be changed.';
                END IF;

                IF OLD.status IN ('succeeded','failed') AND NOT (NEW.status <=> OLD.status) THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A settled refund is a historical record. A retry is a new refund.';
                END IF;
            END
        SQL);

        // A refund is money returned, and it is never deleted. A mistaken one
        // is answered by a new financial act with its own record.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER refunds_no_delete
            BEFORE DELETE ON refunds
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Refunds are append-only.';
            END
        SQL);

        // ---------------------------------------------------------------
        // The order status list gains one member.
        // ---------------------------------------------------------------

        DB::statement('ALTER TABLE orders DROP CONSTRAINT chk_orders_status');

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_status CHECK (
                status IN (
                    'pending_payment','paid','processing','fulfilled',
                    'cancelled','payment_failed','payment_expired','refunded'
                )
            )
        SQL);

        // And the freeze covers it. A refunded order's figures describe money
        // that was genuinely received once, so they stay exactly as they are.
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
        DB::unprepared('DROP TRIGGER IF EXISTS refunds_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS refunds_frozen_request');

        Schema::dropIfExists('refunds');

        DB::unprepared('DROP TRIGGER IF EXISTS orders_frozen_after_payment');

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

        DB::statement('ALTER TABLE orders DROP CONSTRAINT chk_orders_status');

        DB::statement(<<<'SQL'
            ALTER TABLE orders
            ADD CONSTRAINT chk_orders_status CHECK (
                status IN (
                    'pending_payment','paid','processing','fulfilled',
                    'cancelled','payment_failed','payment_expired'
                )
            )
        SQL);
    }
};

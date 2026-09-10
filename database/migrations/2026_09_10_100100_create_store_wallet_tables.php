<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Store Wallet, and the ledger that is its only source of truth.
 *
 * THE THIRD WALLET, AND DELIBERATELY NOT EITHER OF THE OTHER TWO.
 *
 *   CreditWallet   bidding credits. A count. Spent on bids, gone when spent.
 *   CashWallet     real money the platform received. GHS.
 *   StoreWallet    what a losing bidder's purchased credits actually cost,
 *                  returned as purchasing power rather than as money or as
 *                  credits.
 *
 * Structurally parallel to the credit and cash ledgers, and held to the same
 * rules: `store_wallet_transactions` is authoritative, rows are append-only,
 * `balance_minor` is a materialized projection of it, and amounts are integer
 * minor units. There are no lots here -- Store Wallet value is money-shaped,
 * every pesewa is the same as every other, and nothing expires.
 *
 * A separate table rather than a column on either of the other wallets, for
 * the same reason cash and credits are separate: it makes a conversion between
 * them something nobody can write by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_wallets', function (Blueprint $table) {
            $table->id();

            // One wallet per user, enforced by the database.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Materialized, never authoritative.
            $table->unsignedBigInteger('balance_minor')->default(0);

            $table->char('currency', 3)->default('GHS');

            $table->timestamps();
        });

        Schema::create('store_wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_wallet_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);

            // Signed: positive adds value, negative removes it.
            $table->bigInteger('amount_minor');

            // The balance immediately after this row, so the ledger is
            // verifiable by a single scan.
            $table->unsignedBigInteger('balance_after_minor');

            $table->char('currency', 3)->default('GHS');

            // What business event caused this movement -- an auction loss, or
            // the order it was committed to or released from. Polymorphic.
            $table->nullableMorphs('reference');

            // Unique, so a repeated event produces one movement. For an
            // auction loss it is derived from the auction and the user, so a
            // re-run sweep and two concurrent closures converge on one row.
            $table->string('idempotency_key', 191)->nullable();

            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Created only. An append-only ledger has no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_wallet_id', 'created_at']);
            $table->index(['store_wallet_id', 'type']);
            $table->unique('idempotency_key');
        });

        Schema::create('store_wallet_credit_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_wallet_transaction_id')->constrained()->restrictOnDelete();

            // The lot whose consumption produced part of this issuance. A lot
            // cannot be deleted, so nothing here can be orphaned.
            $table->foreignId('credit_lot_id')->constrained()->restrictOnDelete();

            // The lot's own frozen figures, copied here so this table alone
            // can re-run the arithmetic even if the lot record disagrees.
            $table->string('source_type', 40);
            $table->unsignedBigInteger('credits');
            $table->unsignedBigInteger('lot_acquisition_amount_minor');
            $table->unsignedBigInteger('lot_original_amount');
            $table->unsignedBigInteger('amount_minor');

            // The truncated fraction of the intermediate division, kept so a
            // per-lot valuation can be recomputed exactly from this table.
            $table->unsignedInteger('remainder_numerator');

            $table->char('currency', 3)->default('GHS');

            $table->timestamp('created_at')->useCurrent();

            $table->index('store_wallet_transaction_id');
            $table->index('credit_lot_id');
        });

        // ---------------------------------------------------------------
        // Constraints. The last line of defence, repeated below the
        // application because a service can be bypassed by a console command.
        // ---------------------------------------------------------------

        DB::statement(<<<'SQL'
            ALTER TABLE store_wallet_transactions
            ADD CONSTRAINT chk_store_wallet_tx_amount_nonzero CHECK (amount_minor <> 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE store_wallet_credit_sources
            ADD CONSTRAINT chk_store_wallet_source_credits_positive CHECK (credits > 0),
            ADD CONSTRAINT chk_store_wallet_source_original_positive CHECK (lot_original_amount > 0)
        SQL);

        // ---------------------------------------------------------------
        // Append-only enforcement. "Never edit financial history" is held by
        // the database itself, exactly as it is for the other ledgers.
        // ---------------------------------------------------------------

        foreach (['store_wallet_transactions', 'store_wallet_credit_sources'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Store Wallet history is append-only: {$table} rows cannot be updated. Post a compensating entry instead.';
                END
            SQL);

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Store Wallet history is append-only: {$table} rows cannot be deleted. Post a compensating entry instead.';
                END
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['store_wallet_transactions', 'store_wallet_credit_sources'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }

        Schema::dropIfExists('store_wallet_credit_sources');
        Schema::dropIfExists('store_wallet_transactions');
        Schema::dropIfExists('store_wallets');
    }
};

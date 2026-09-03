<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The real-money ledger.
 *
 * Structurally similar to the credit ledger and deliberately separate from it.
 * Cash and bidding credits are different things: cash is a liability to the
 * customer, credits are a virtual entitlement with their own expiry and
 * refund rules. A single shared balance would make it possible for a credit
 * refund to be settled as a cash liability, which is a class of bug worth
 * making structurally impossible rather than merely avoiding.
 *
 * Amounts are integer minor units -- pesewas for GHS -- matching the Money
 * value object from the rules-engine stage. No float, ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_wallets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Materialized from the ledger, never authoritative.
            $table->unsignedBigInteger('balance_minor')->default(0);

            $table->char('currency', 3)->default('GHS');

            $table->timestamps();
        });

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cash_wallet_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);

            // Signed integer minor units.
            $table->bigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');

            // Recorded per row so a historical entry stays interpretable even
            // if the wallet's currency were ever migrated.
            $table->char('currency', 3)->default('GHS');

            $table->nullableMorphs('reference');

            $table->string('idempotency_key', 191)->nullable();

            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['cash_wallet_id', 'created_at']);
            $table->index(['cash_wallet_id', 'type']);
            $table->unique('idempotency_key');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cash_transactions
            ADD CONSTRAINT chk_cash_tx_amount_nonzero CHECK (amount_minor <> 0)
        SQL);

        // Append-only, for the same reasons as the credit ledger.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cash_transactions_no_update
            BEFORE UPDATE ON cash_transactions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Financial history is append-only: cash_transactions rows cannot be updated. Post a compensating entry instead.';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cash_transactions_no_delete
            BEFORE DELETE ON cash_transactions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Financial history is append-only: cash_transactions rows cannot be deleted. Post a compensating entry instead.';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cash_transactions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS cash_transactions_no_delete');

        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('cash_wallets');
    }
};

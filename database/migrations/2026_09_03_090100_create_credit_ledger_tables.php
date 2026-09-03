<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bidding-credit ledger.
 *
 * The ledger is the authoritative record. `credit_wallets.balance` is a
 * materialized convenience that must always be derivable from it, and is
 * written only inside the same transaction as the ledger row that justifies
 * it.
 *
 * Credits are whole integers. There is no float or decimal anywhere here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();

            // One wallet per user, enforced by the database rather than by
            // remembering to check first.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Materialized, never authoritative. Kept in step with the ledger
            // inside one transaction, and verifiable by the reconciler.
            $table->unsignedBigInteger('balance')->default(0);

            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('credit_wallet_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);

            // Signed: positive adds credits, negative removes them.
            $table->bigInteger('amount');

            // The wallet's balance immediately after this row. Storing it
            // makes the ledger verifiable by a single scan rather than by
            // replaying every prior transaction.
            $table->unsignedBigInteger('balance_after');

            // What business event caused this movement -- a payment, a bid, an
            // adjustment. Polymorphic because those live in different tables,
            // several of which do not exist yet.
            $table->nullableMorphs('reference');

            $table->string('idempotency_key', 191)->nullable();

            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Created only. An append-only ledger has no updated_at, because
            // nothing is ever updated.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['credit_wallet_id', 'created_at']);
            $table->index(['credit_wallet_id', 'type']);
            $table->index(['credit_wallet_id', 'id']);
            $table->unique('idempotency_key');
        });

        Schema::create('credit_lots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('credit_wallet_id')->constrained()->cascadeOnDelete();

            // The transaction that brought this lot into existence.
            $table->foreignId('credit_transaction_id')->constrained()->restrictOnDelete();

            $table->string('source_type', 40);

            $table->unsignedBigInteger('original_amount');
            $table->unsignedBigInteger('remaining_amount');

            // Null means the credits do not expire, which is the default for
            // purchased credits.
            $table->timestamp('expires_at')->nullable();

            // Set when a lot's remaining balance reaches zero, so the
            // allocator can skip exhausted lots cheaply.
            $table->timestamp('exhausted_at')->nullable();

            $table->timestamps();

            // Supports the allocator's query: spendable lots for a wallet,
            // ordered for consumption.
            $table->index(['credit_wallet_id', 'remaining_amount']);
            $table->index(['credit_wallet_id', 'expires_at']);
            $table->index(['credit_wallet_id', 'source_type']);
        });

        Schema::create('credit_lot_consumptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('credit_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_transaction_id')->constrained()->restrictOnDelete();

            // Always positive: the quantity taken from that lot.
            $table->unsignedBigInteger('amount');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['credit_transaction_id']);
            $table->index(['credit_lot_id', 'created_at']);
        });

        // ---------------------------------------------------------------
        // Constraints: the last line of defence.
        //
        // Application services already enforce all of this, but a service can
        // be bypassed by a console command, a future import, or a mistake.
        // The database cannot.
        // ---------------------------------------------------------------

        DB::statement(<<<'SQL'
            ALTER TABLE credit_transactions
            ADD CONSTRAINT chk_credit_tx_amount_nonzero CHECK (amount <> 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE credit_lots
            ADD CONSTRAINT chk_credit_lot_original_positive CHECK (original_amount > 0),
            ADD CONSTRAINT chk_credit_lot_remaining_within_original CHECK (
                remaining_amount <= original_amount
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE credit_lot_consumptions
            ADD CONSTRAINT chk_credit_consumption_positive CHECK (amount > 0)
        SQL);

        // ---------------------------------------------------------------
        // Append-only enforcement.
        //
        // "Never edit financial history" is a rule the database itself should
        // hold, not one that depends on every future contributor knowing it.
        // These triggers make UPDATE and DELETE impossible on the ledger and
        // on the consumption records, by any client, including a direct SQL
        // session. Corrections must be made with compensating entries.
        // ---------------------------------------------------------------

        foreach (['credit_transactions', 'credit_lot_consumptions'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Financial history is append-only: {$table} rows cannot be updated. Post a compensating entry instead.';
                END
            SQL);

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Financial history is append-only: {$table} rows cannot be deleted. Post a compensating entry instead.';
                END
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['credit_transactions', 'credit_lot_consumptions'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }

        Schema::dropIfExists('credit_lot_consumptions');
        Schema::dropIfExists('credit_lots');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_wallets');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One customer's purchase of one credit package.
 *
 * The row carries an immutable snapshot of what was bought -- package name,
 * credit quantity, price, currency -- taken when the transaction was
 * initialized. Fulfilment reads the snapshot, never the package record, so an
 * administrator repricing a package tomorrow cannot change what a purchase
 * made today is worth or how many credits it grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Restricted rather than cascading: a package that has been bought
            // cannot be deleted out from under its purchase history.
            $table->foreignId('credit_package_id')->nullable()
                ->constrained('credit_packages')->restrictOnDelete();

            // ---- Immutable snapshot -------------------------------------
            $table->string('package_name_snapshot', 100);
            $table->unsignedBigInteger('credit_amount');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('GHS');

            $table->string('status', 30)->default('pending');

            // ---- Provider -----------------------------------------------
            $table->string('payment_provider', 30)->default('paystack');

            // Our reference, generated server-side and sent to the provider.
            // Unique, so a provider callback maps to exactly one purchase.
            $table->string('provider_reference', 64)->unique();

            // The provider's own transaction id. Paystack ids are large
            // integers, so an unsigned 64-bit column.
            $table->unsignedBigInteger('provider_transaction_id')->nullable();

            $table->string('provider_channel', 40)->nullable();

            // The key under which fulfilment runs, so repeated webhooks and
            // callbacks converge on one financial effect.
            $table->string('idempotency_key', 191)->unique();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('provider_transaction_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE credit_purchases
            ADD CONSTRAINT chk_credit_purchases_credits_positive CHECK (credit_amount > 0),
            ADD CONSTRAINT chk_credit_purchases_amount_positive CHECK (amount_minor > 0),
            ADD CONSTRAINT chk_credit_purchases_status CHECK (
                status IN ('pending','payment_processing','paid','fulfilled','failed','cancelled','reversed')
            )
        SQL);

        // Every state change, kept as evidence. When a customer disputes what
        // happened to a payment, this is the record that answers it.
        Schema::create('credit_purchase_transitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('credit_purchase_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();

            $table->foreignId('caused_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['credit_purchase_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchase_transitions');
        Schema::dropIfExists('credit_purchases');
    }
};

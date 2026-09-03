<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every webhook the platform accepts, stored before it is acted on.
 *
 * Providers retry. Paystack retries. Storing the event first, under a unique
 * constraint on the provider's own event identity, is what turns "this arrived
 * five times" into "this was handled once" -- and keeps the payload on record
 * for a failed event to be examined or replayed rather than lost.
 *
 * Signatures are recorded but secrets never are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 30);

            // The provider's identity for this event. For Paystack this is
            // derived from the event type and the transaction id, since
            // Paystack does not send a dedicated event id header.
            $table->string('provider_event_id', 191);

            $table->string('event_type', 100);

            // The verified payload, kept whole so a failed event can be
            // reprocessed exactly as it arrived.
            $table->json('payload');

            // The signature that was verified. Useful for audit; it is a
            // digest, not a credential, and the secret is never stored.
            $table->string('signature', 191)->nullable();

            $table->string('processing_status', 30)->default('received');
            $table->string('processing_error', 1000)->nullable();

            // The purchase this event resolved to, once known.
            $table->foreignId('credit_purchase_id')->nullable()
                ->constrained('credit_purchases')->nullOnDelete();

            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            // The duplicate-delivery defence, enforced by the database rather
            // than by an application check that would race under concurrent
            // retries.
            $table->unique(['provider', 'provider_event_id']);

            $table->index(['processing_status', 'received_at']);
            $table->index(['provider', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};

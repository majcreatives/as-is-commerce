<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            // The operation being guarded, e.g. "credit.adjust". Scoping by
            // operation means a caller cannot accidentally suppress a
            // different kind of work by reusing a key it saw elsewhere.
            $table->string('operation', 100);

            // Supplied by the caller -- a payment provider's event id, or a
            // generated UUID. Never derived from a timestamp, which would not
            // be stable across the retry it is meant to protect against.
            $table->string('idempotency_key', 191);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('pending');

            // The successful result, replayed verbatim on a retry so the
            // caller receives what it would have received the first time.
            $table->json('result')->nullable();

            $table->string('failure_reason', 500)->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // The whole mechanism rests on this constraint. Two concurrent
            // requests carrying the same key cannot both insert: the second
            // blocks on the index until the first commits, then fails and
            // reads the winner's result instead of repeating the work.
            $table->unique(['operation', 'idempotency_key']);

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

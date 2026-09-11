<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scopes idempotency keys to the user who owns the operation.
 *
 * The original index was unique on (operation, idempotency_key) alone. That
 * treats a key as global to the operation, when most keys are actually
 * user-specific -- a bid key, a fulfilment key. Two different customers using
 * the same key (a collision is unlikely but a shared key format is not
 * impossible) would then block each other, and the second would even replay
 * the first customer's stored result. Scoping to `user_id` keeps each
 * customer's keys independent while preserving exactly-once for the same
 * operation, same key, same user -- which is the guarantee the guard exists
 * to provide.
 *
 * `user_id` is nullable because administrative operations have no owning
 * customer, and MySQL keeps multiple NULL values distinct in a unique index,
 * so a null-keyed operation can never collide with a customer's key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique(['operation', 'idempotency_key']);
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->unique(['operation', 'user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique(['operation', 'user_id', 'idempotency_key']);
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->unique(['operation', 'idempotency_key']);
        });
    }
};

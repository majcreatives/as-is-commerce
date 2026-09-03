<?php

declare(strict_types=1);

namespace App\Domain\Shared\Idempotency;

use App\Enums\IdempotencyStatus;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a financial operation at most once per key.
 *
 * The problem this solves is concrete: a payment provider retries a webhook,
 * a customer double-taps a button, a queued job runs twice after a timeout.
 * Without a guard each of those adds credits a second time, and the customer
 * ends up with money the business never received.
 *
 * How it works, and why in this order:
 *
 *   1. A completed key short-circuits immediately and replays the stored
 *      result. The caller receives what it would have received first time.
 *
 *   2. Otherwise the key is claimed by INSERT, inside the same transaction as
 *      the work. The unique index on (operation, key) is what makes this
 *      safe: two concurrent requests cannot both insert, so exactly one does
 *      the work.
 *
 *   3. The loser's INSERT blocks on that index until the winner's transaction
 *      settles, then fails with a duplicate-key error. It re-reads the row
 *      with a locking read -- necessary under REPEATABLE READ, where a plain
 *      read would still see the pre-insert snapshot -- and returns the
 *      winner's result.
 *
 *   4. If the work throws, the whole transaction rolls back, including the
 *      claim. The key is then free to retry, which is the correct outcome for
 *      an operation that had no effect.
 *
 * The claim and the work share one transaction on purpose. Claiming
 * separately would leave a key marked as taken for an operation that never
 * happened, permanently blocking a legitimate retry.
 */
class IdempotencyGuard
{
    /**
     * @template TReturn of array<string, mixed>
     *
     * @param  Closure(IdempotencyKey): TReturn  $work
     * @return array<string, mixed>
     *
     * @throws ConcurrentOperationInProgress
     */
    public function execute(
        string $operation,
        string $key,
        ?int $userId,
        Closure $work,
    ): array {
        $existing = $this->find($operation, $key);

        if ($existing !== null) {
            return $this->resolveExisting($existing, $operation, $key);
        }

        return DB::transaction(function () use ($operation, $key, $userId, $work): array {
            try {
                $claim = IdempotencyKey::create([
                    'operation' => $operation,
                    'idempotency_key' => $key,
                    'user_id' => $userId,
                    'status' => IdempotencyStatus::Pending,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another request claimed this key first. A locking read is
                // required here: a plain SELECT inside this transaction would
                // still see the snapshot taken before the other transaction
                // committed, and would find nothing.
                $winner = IdempotencyKey::query()
                    ->where('operation', $operation)
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($winner === null) {
                    // The other transaction rolled back after we collided
                    // with it. Nothing happened, so the caller may retry.
                    throw ConcurrentOperationInProgress::for($operation, $key);
                }

                return $this->resolveExisting($winner, $operation, $key);
            }

            $result = $work($claim);

            $claim->update([
                'status' => IdempotencyStatus::Completed,
                'result' => $result,
                'completed_at' => now(),
            ]);

            Log::info('Idempotent operation completed', [
                'operation' => $operation,
                'idempotency_key' => $key,
                'user_id' => $userId,
                'result' => $result,
            ]);

            return $result;
        });
    }

    /**
     * Decide what to do about a key that already exists.
     *
     * @return array<string, mixed>
     */
    private function resolveExisting(IdempotencyKey $record, string $operation, string $key): array
    {
        if ($record->isCompleted()) {
            Log::info('Idempotent operation replayed', [
                'operation' => $operation,
                'idempotency_key' => $key,
                'original_completed_at' => $record->completed_at?->toIso8601String(),
            ]);

            return $record->result ?? [];
        }

        if ($record->isPending()) {
            throw ConcurrentOperationInProgress::for($operation, $key);
        }

        // A previously failed attempt had no financial effect, so the key is
        // released and the caller may try again.
        $record->delete();

        throw new RetryableIdempotentOperation(
            "Operation [{$operation}] with key [{$key}] previously failed and may be retried."
        );
    }

    private function find(string $operation, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * Record that an attempt failed, so the reason survives for diagnosis.
     *
     * Called outside the rolled-back transaction, since anything written
     * inside it is gone.
     */
    public function recordFailure(string $operation, string $key, Throwable $e): void
    {
        Log::warning('Idempotent operation failed', [
            'operation' => $operation,
            'idempotency_key' => $key,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IdempotencyStatus;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    protected $model = IdempotencyKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation' => 'test.operation',
            'idempotency_key' => Str::uuid()->toString(),
            // A user-owned operation by default. Set null explicitly where a
            // test models an administrative key with no owning customer.
            'user_id' => User::factory(),
            'status' => IdempotencyStatus::Pending,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function completed(array $result = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => IdempotencyStatus::Completed,
            'result' => $result,
            'completed_at' => now(),
        ]);
    }
}

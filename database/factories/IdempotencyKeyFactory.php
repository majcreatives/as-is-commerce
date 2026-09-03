<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IdempotencyStatus;
use App\Models\IdempotencyKey;
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

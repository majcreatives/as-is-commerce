<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 *
 * Builds a refund row directly, for tests about a state rather than about how
 * that state is reached.
 *
 * IT ASKS NO PROVIDER AND CHECKS NO ELIGIBILITY. Both belong to the actions,
 * and a refund that skipped them is a state the application never produces on
 * its own. Anything testing the cap, the concurrency, the provider handling or
 * the order transition should go through `RequestRefund` and `ProcessRefund`.
 * What this is for is the reconciliation and queue tests, which need rows in
 * awkward states that the actions are specifically designed never to create.
 *
 * The order and payment must be supplied. Inventing them would produce a
 * refund against a payment that never succeeded, which every constraint in the
 * application exists to prevent.
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => PaymentProvider::Paystack,
            'amount_minor' => 10_000,
            'currency' => 'GHS',
            'status' => RefundStatus::Pending,
            'reason' => RefundReason::InventoryConflict,
            'idempotency_key' => 'refund-test-'.Str::uuid()->toString(),
            'requested_at' => Carbon::now(),
        ];
    }

    public function processing(?string $providerReference = 'RF-TEST-1'): static
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Processing,
            'provider_reference' => $providerReference,
            'provider_status' => 'pending',
            'processed_at' => Carbon::now(),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Succeeded,
            'provider_reference' => 'RF-TEST-'.Str::random(6),
            'provider_status' => 'processed',
            'processed_at' => Carbon::now(),
            // Required by a CHECK constraint: a succeeded refund must say
            // when, because a row claiming an outcome with no evidence of it
            // is worse than no row.
            'succeeded_at' => Carbon::now(),
        ]);
    }

    public function failed(string $reason = 'The provider refused the refund.'): static
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Failed,
            'failure_reason' => $reason,
            'failed_at' => Carbon::now(),
        ]);
    }

    /**
     * Every column is guarded, so the model has no fillable attributes at all.
     * The factory writes them directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Refund
    {
        $refund = new Refund;
        $refund->forceFill($attributes);

        return $refund;
    }
}

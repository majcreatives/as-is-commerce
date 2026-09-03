<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditPurchaseStatus;
use App\Enums\PaymentProvider;
use App\Models\CreditPackage;
use App\Models\CreditPurchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CreditPurchase>
 */
class CreditPurchaseFactory extends Factory
{
    protected $model = CreditPurchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credit_package_id' => CreditPackage::factory(),
            'package_name_snapshot' => 'Popular',
            'credit_amount' => 500,
            'amount_minor' => 4_500,
            'currency' => 'GHS',
            'status' => CreditPurchaseStatus::Pending,
            'payment_provider' => PaymentProvider::Paystack,
            'provider_reference' => 'AIC-TEST-'.strtoupper(Str::random(16)),
            'idempotency_key' => Str::uuid()->toString(),
        ];
    }

    public function status(CreditPurchaseStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    public function awaitingPayment(): static
    {
        return $this->status(CreditPurchaseStatus::PaymentProcessing);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CreditPurchaseStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function fulfilled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CreditPurchaseStatus::Fulfilled,
            'paid_at' => now(),
            'fulfilled_at' => now(),
        ]);
    }
}

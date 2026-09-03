<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreditPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CreditPackage>
 */
class CreditPackageFactory extends Factory
{
    protected $model = CreditPackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->sentence(),
            'credit_amount' => 500,
            'price_minor' => 4_500,
            'currency' => 'GHS',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function priced(int $creditAmount, int $priceMinor): static
    {
        return $this->state(fn (array $attributes): array => [
            'credit_amount' => $creditAmount,
            'price_minor' => $priceMinor,
        ]);
    }
}

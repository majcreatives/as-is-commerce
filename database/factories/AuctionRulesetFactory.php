<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ForfeitPolicy;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuctionRuleset>
 */
class AuctionRulesetFactory extends Factory
{
    protected $model = AuctionRuleset::class;

    /**
     * A coherent, internally consistent default configuration.
     *
     * No default checkout price: a ruleset carries auction defaults, and the
     * price belongs to the product being auctioned.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'version' => 1,
            'status' => RulesetStatus::Draft,

            'bid_cost_credits' => 1,
            'unique_leader' => true,
            'minimum_bid_interval_ms' => 1000,

            'base_duration_seconds' => 300,
            'closing_window_seconds' => 10,
            'extension_seconds' => 10,
            'max_extensions' => 20,
            'max_extension_total_seconds' => 300,

            'checkout_deadline_minutes' => 60,
            'forfeit_policy' => ForfeitPolicy::Relist,

            'default_checkout_price_minor' => null,
            'delivery_fee_minor' => 0,
            'currency' => 'GHS',
            'tax_bps' => 0,

            'is_default' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RulesetStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RulesetStatus::Archived,
            'archived_at' => now(),
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RulesetStatus::Active,
            'activated_at' => now(),
            'is_default' => true,
        ]);
    }

    public function withDefaultCheckoutPrice(int $minor): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_checkout_price_minor' => $minor,
        ]);
    }

    public function withoutExtensions(): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_extensions' => 0,
            'extension_seconds' => 0,
            'max_extension_total_seconds' => 0,
        ]);
    }
}

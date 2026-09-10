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

            // Nullable bid rules stay null: their business values have not
            // been decided, and a factory inventing one would quietly become
            // the number everybody tests against.
            'minimum_bid_credits' => null,
            'minimum_bid_increment_credits' => null,
            'allow_bid_increase' => null,
            'minimum_bid_interval_ms' => 3000,

            'base_duration_seconds' => 300,
            'closing_window_seconds' => 10,
            'extension_seconds' => 10,
            'max_extensions' => 20,
            'max_extension_total_seconds' => 300,

            'checkout_deadline_minutes' => 60,
            'forfeit_policy' => ForfeitPolicy::Relist,

            'buy_now_enabled' => true,
            'buy_now_credit_discount_enabled' => true,

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

    public function withBidRules(?int $minimum = null, ?int $increment = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'minimum_bid_credits' => $minimum,
            'minimum_bid_increment_credits' => $increment,
        ]);
    }

    public function withoutBuyNow(): static
    {
        return $this->state(fn (array $attributes): array => [
            'buy_now_enabled' => false,
            'buy_now_credit_discount_enabled' => false,
        ]);
    }

    /**
     * No minimum interval between one bidder's successive bids.
     *
     * The default carries a three-second throttle, which is realistic but
     * makes any test that places two bids from the same person in quick
     * succession fail for a reason it was not testing.
     */
    public function withoutThrottle(): static
    {
        return $this->state(fn (array $attributes): array => ['minimum_bid_interval_ms' => 0]);
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

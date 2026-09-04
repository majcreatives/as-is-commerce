<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'sku' => strtoupper(Str::random(4)).'-'.fake()->unique()->numberBetween(10000, 999999),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'name' => $name,
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'condition' => ProductCondition::New,
            'status' => ProductStatus::Draft,
            'buy_now_price_minor' => 550_000,
            'currency' => 'GHS',
            // Stock starts at zero and is moved only by the inventory
            // service, the same path production uses.
            'stock_on_hand' => 0,
            'stock_reserved' => 0,
        ];
    }

    public function status(ProductStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'published_at' => $status === ProductStatus::Draft ? null : now(),
        ]);
    }

    public function active(): static
    {
        return $this->status(ProductStatus::Active);
    }

    public function draft(): static
    {
        return $this->status(ProductStatus::Draft);
    }

    public function archived(): static
    {
        return $this->status(ProductStatus::Archived);
    }

    public function condition(ProductCondition $condition): static
    {
        return $this->state(fn (array $attributes): array => ['condition' => $condition]);
    }

    public function pricedAt(int $minor): static
    {
        return $this->state(fn (array $attributes): array => ['buy_now_price_minor' => $minor]);
    }

    public function withoutBrand(): static
    {
        return $this->state(fn (array $attributes): array => ['brand_id' => null]);
    }

    /**
     * Seed stock directly.
     *
     * Only for tests that need a starting quantity without exercising the
     * ledger. Tests about inventory itself must go through InventoryService,
     * so they test the path production uses.
     */
    public function withStock(int $onHand, int $reserved = 0): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock_on_hand' => $onHand,
            'stock_reserved' => $reserved,
        ]);
    }
}

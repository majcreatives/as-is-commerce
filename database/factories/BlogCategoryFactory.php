<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogCategory>
 */
class BlogCategoryFactory extends Factory
{
    protected $model = BlogCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->sentence(),
            'sort_order' => 0,
            'status' => CatalogStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => CatalogStatus::Inactive]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => CatalogStatus::Archived]);
    }
}

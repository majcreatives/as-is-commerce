<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CatalogStatus;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name),
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

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SuccessStory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuccessStory>
 */
class SuccessStoryFactory extends Factory
{
    protected $model = SuccessStory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'title' => fake()->randomElement(['Accra', 'Kumasi', 'Takoradi', 'Tema']),
            'quote' => fake()->realText(180),
            'image_path' => null,
            'featured' => false,
            // Published but not featured: a story belongs on the site before it
            // earns a homepage slot, and featured() opts into that.
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes): array => ['featured' => true]);
    }
}

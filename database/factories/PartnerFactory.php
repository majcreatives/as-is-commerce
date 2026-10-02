<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->company());

        return [
            'name' => $name,
            'url' => 'https://'.Str::slug($name, '').fake()->unique()->numberBetween(1, 999999).'.test',
            'logo_path' => null,
            'description' => fake()->sentence(),
            'sort_order' => 0,
            // Published by default, because a factory that builds something the
            // public site cannot see produces tests that pass for the wrong
            // reason. Unpublished is the state you opt into with inactive().
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}

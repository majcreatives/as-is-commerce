<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => fake()->word(),
            'type' => SettingType::String,
            'group' => 'general',
            'label' => fake()->words(2, true),
            'description' => null,
            'is_public' => false,
        ];
    }

    public function ofType(SettingType $type, ?string $value = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'value' => $value ?? $attributes['value'],
        ]);
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes): array => ['is_public' => true]);
    }
}

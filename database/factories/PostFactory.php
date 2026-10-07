<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 9999),
            'title' => $title,
            'excerpt' => fake()->paragraph(2),
            'body' => fake()->paragraphs(5, true),
            'image_path' => null,
            'active' => false,
            'published_at' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function published(): self
    {
        return $this->state(fn () => [
            'active' => true,
            'published_at' => now(),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => [
            'active' => false,
            'published_at' => now(),
        ]);
    }

    public function undated(): self
    {
        return $this->state(fn () => [
            'active' => true,
            'published_at' => null,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NewsletterSubscriber>
 */
class NewsletterSubscriberFactory extends Factory
{
    protected $model = NewsletterSubscriber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'source' => 'footer',
            // Pending by default, because that is what a submitted form really
            // produces. A factory whose default is `subscribed` would let a test
            // pass without ever proving the confirmation link works.
            'status' => NewsletterStatus::Pending,
            'confirmation_token' => Str::random(64),
            'unsubscribe_token' => Str::random(64),
            'consented_at' => null,
            'unsubscribed_at' => null,
        ];
    }

    /**
     * A row that has been through the confirmation link, and therefore has a
     * consent timestamp.
     */
    public function subscribed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NewsletterStatus::Subscribed,
            'consented_at' => now(),
            'confirmation_token' => null,
        ]);
    }

    public function unsubscribed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => NewsletterStatus::Unsubscribed,
            'unsubscribed_at' => now(),
            'confirmation_token' => null,
        ]);
    }
}

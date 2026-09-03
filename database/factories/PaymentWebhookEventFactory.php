<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\WebhookProcessingStatus;
use App\Models\PaymentWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentWebhookEvent>
 */
class PaymentWebhookEventFactory extends Factory
{
    protected $model = PaymentWebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => PaymentProvider::Paystack,
            'provider_event_id' => 'charge.success:'.fake()->unique()->numberBetween(1, 999999999),
            'event_type' => 'charge.success',
            'payload' => ['event' => 'charge.success', 'data' => []],
            'processing_status' => WebhookProcessingStatus::Received,
            'received_at' => now(),
        ];
    }
}

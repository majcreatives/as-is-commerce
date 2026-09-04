<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\WebhookProcessingStatus;
use Database\Factories\PaymentWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A webhook the platform accepted, stored before it was acted on.
 *
 * Storing first means an event that fails to process is still on record with
 * its payload intact, so it can be examined or replayed rather than lost. The
 * unique index on (provider, provider_event_id) is what makes a redelivery
 * recognisable as one.
 *
 * @property int $id
 * @property PaymentProvider $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string|null $signature
 * @property WebhookProcessingStatus $processing_status
 * @property string|null $processing_error
 * @property int|null $credit_purchase_id
 * @property int|null $order_payment_id
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
class PaymentWebhookEvent extends Model
{
    /** @use HasFactory<PaymentWebhookEventFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'processing_status' => WebhookProcessingStatus::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CreditPurchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CreditPurchase::class, 'credit_purchase_id');
    }

    /**
     * The product payment this event resolved to, if it was one.
     *
     * An event resolves to a credit purchase or to an order payment, never
     * both: the reference it carries belongs to exactly one of them. Two
     * nullable references rather than a polymorphic pair, so both foreign keys
     * are real and a dangling one is impossible.
     *
     * @return BelongsTo<OrderPayment, $this>
     */
    public function orderPayment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'order_payment_id');
    }

    /**
     * What this event was about, in words, for a staff listing.
     */
    public function subjectLabel(): string
    {
        if ($this->order_payment_id !== null) {
            return 'Order '.($this->orderPayment?->order->order_number ?? '#'.$this->order_payment_id);
        }

        if ($this->credit_purchase_id !== null) {
            return 'Credit purchase #'.$this->credit_purchase_id;
        }

        return 'Unmatched';
    }

    /**
     * The payload with anything sensitive removed, for display to staff.
     *
     * Paystack echoes card and authorization details on a charge event. None
     * of it is needed to explain a payment, and an admin screen is the wrong
     * place for it to surface.
     *
     * @return array<string, mixed>
     */
    public function redactedPayload(): array
    {
        $payload = $this->payload;

        if (isset($payload['data']) && is_array($payload['data'])) {
            unset(
                $payload['data']['authorization'],
                $payload['data']['customer']['metadata'],
                $payload['data']['log'],
            );
        }

        return $payload;
    }
}

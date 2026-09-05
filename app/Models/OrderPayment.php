<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One attempt to pay for one order.
 *
 * WHY THE AMOUNT LIVES HERE. It records what was actually opened with the
 * provider, and verification compares the provider's answer against this row
 * rather than against the order. Reading the expected amount off the order at
 * verification time would mean checking the provider against a figure that
 * could have moved -- which is not a check at all. A trigger refuses any
 * change to the amount, currency, reference or order.
 *
 * SEVERAL ATTEMPTS PER ORDER ARE NORMAL. A customer who abandons a payment
 * page and comes back gets a new attempt with a new reference. Each keeps its
 * own history, and at most one ever reaches Success.
 *
 * NOTHING HERE IS A CREDENTIAL. The authorization URL is where to send the
 * payer and the access code is scoped to this transaction. The Paystack secret
 * key is never stored, never logged, and never leaves the server.
 *
 * @property int $id
 * @property int $order_id
 * @property PaymentProvider $provider
 * @property string $provider_reference
 * @property int $amount_minor
 * @property string $currency
 * @property OrderPaymentStatus $status
 * @property string|null $authorization_url
 * @property string|null $access_code
 * @property int|null $provider_transaction_id
 * @property string|null $provider_channel
 * @property string $idempotency_key
 * @property Carbon|null $paid_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 */
class OrderPayment extends Model
{
    /**
     * Nothing is mass assignable. A payment attempt is written by exactly one
     * action, from a server-side order snapshot, and never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => OrderPaymentStatus::class,
            'amount_minor' => 'integer',
            'provider_transaction_id' => 'integer',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * The amount this attempt was opened for.
     *
     * The only figure a verification is allowed to check against.
     */
    public function amount(): Money
    {
        return Money::fromMinor($this->amount_minor, $this->currency);
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Money returned against this attempt.
     *
     * The attempt itself never changes: after a full refund this row still
     * reads `Success` for its original amount, because that is what happened.
     * What was paid and what was given back are two questions with two
     * answers, and this relation is the second one.
     *
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [OrderPaymentStatus::Initiated, OrderPaymentStatus::Pending]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', OrderPaymentStatus::Success);
    }
}

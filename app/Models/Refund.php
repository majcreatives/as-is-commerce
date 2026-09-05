<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use App\Enums\PaymentProvider;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Money the platform received and gave back.
 *
 * A REFUND POINTS AT A PAYMENT AND NEVER TOUCHES IT. After this row reaches
 * `Succeeded` the `OrderPayment` it refers to still reads `Success` for its
 * full original amount, because that is what happened. The platform was paid,
 * and then it paid back; both are true, and each has its own record.
 *
 * WHAT IT CANNOT DO. It returns no credits. Auction bid credits are consumed
 * the moment a bid is accepted and stay consumed through every outcome --
 * losing, being outbid, an auction cancelled, a settlement forfeited, and a
 * payment refunded. There is no column here that could express a credit, and
 * nothing in the refund domain touches the credit ledger in either direction.
 *
 * It restores no stock either. A refund is a movement of money; whether an
 * item is back on the shelf depends on the order's own lifecycle, and a
 * physical return is a business process this stage does not implement.
 *
 * NOTHING REACHES `Succeeded` ON OUR SAY-SO. The row is written `Pending`
 * before the provider is contacted, so a failed call leaves an attempt to be
 * seen rather than vanishing with a rolled-back transaction, and it advances
 * only on the provider's own account of what happened.
 *
 * @property int $id
 * @property int $order_id
 * @property int $order_payment_id
 * @property PaymentProvider $provider
 * @property string|null $provider_reference
 * @property string|null $provider_status
 * @property int $amount_minor
 * @property string $currency
 * @property RefundStatus $status
 * @property RefundReason $reason
 * @property string|null $note
 * @property int $requested_by
 * @property string $idempotency_key
 * @property Carbon $requested_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, LogsActivity;

    /**
     * Nothing is mass assignable.
     *
     * A refund is written by exactly one action, from server-side reads, and
     * never from request input. The browser names an order and a reason; the
     * amount is computed here.
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
            'status' => RefundStatus::class,
            'reason' => RefundReason::class,
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    // --------------------------------------------------------------- Money

    public function amount(): Money
    {
        return Money::fromMinor($this->amount_minor, $this->currency);
    }

    // --------------------------------------------------------------- State

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isInFlight(): bool
    {
        return $this->status->isInFlight();
    }

    public function hasFailed(): bool
    {
        return $this->status === RefundStatus::Failed;
    }

    /**
     * Whether the provider has been contacted about this refund yet.
     */
    public function isAwaitingProvider(): bool
    {
        return $this->status === RefundStatus::Pending;
    }

    // -------------------------------------------------------- Relationships

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The payment this returns. Never modified by the refund.
     *
     * @return BelongsTo<OrderPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'order_payment_id');
    }

    /**
     * The member of staff who asked for it.
     *
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    // -------------------------------------------------------------- Scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Succeeded);
    }

    /**
     * Refunds that may still succeed, and so still hold back part of the
     * refundable amount.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', [RefundStatus::Pending, RefundStatus::Processing]);
    }

    /**
     * Refunds the provider has accepted and not yet settled.
     *
     * The work queue for `refunds:reconcile`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingProvider(Builder $query): Builder
    {
        return $query->where('status', RefundStatus::Processing);
    }

    // ----------------------------------------------------------- Audit log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('refund')
            ->logOnly(['status', 'provider_reference', 'provider_status', 'failure_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Refund [{$this->id}] was {$eventName}";
    }
}

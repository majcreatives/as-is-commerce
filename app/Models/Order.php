<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Orders\ValueObjects\CheckoutPricing;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Concerns\GuardsPaidOrderRecord;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One customer's obligation to pay for one thing, in GHS.
 *
 * AN ORDER IS NOT AN AUCTION, A PAYMENT, OR AN INVENTORY MOVEMENT. Each of
 * those owns its own table and its own lifecycle; an order relates to them.
 * The separation is load-bearing: an auction sits at `PendingSettlement` while
 * its winner's order sits at `PendingPayment`, and after a verified payment
 * the auction becomes `Settled` while the order becomes `Paid`.
 *
 * THE TWO PATHS OWE DIFFERENT AMOUNTS:
 *
 *   Buy Now      subtotal is the product's Buy Now price, less GH₵1 for each
 *                credit this buyer consumed bidding on this auction.
 *   Auction win  subtotal is the auction's own settlement amount -- a low
 *                figure chosen per auction, unrelated to the Buy Now price --
 *                and there is no discount at all.
 *
 * NO CREDITS ARE CHARGED HERE. They were consumed when the bids were placed,
 * permanently. `discount_credits` is a count kept as evidence for the cedis in
 * `discount_minor`; it is not money, and nothing on this model converts one
 * into the other.
 *
 * FROZEN ONCE PAID. From the moment a payment is verified, every commercial
 * column is a historical fact. The model guard refuses a change and a database
 * trigger refuses it again.
 *
 * @property int $id
 * @property string $order_number
 * @property int $user_id
 * @property OrderSource $source
 * @property OrderStatus $status
 * @property int|null $auction_id
 * @property int|null $winning_bid_id
 * @property int|null $delivery_address_id
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $delivery_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $discount_credits
 * @property array<string, mixed> $pricing_snapshot
 * @property bool $holds_reservation
 * @property Carbon|null $payment_due_at
 * @property Carbon|null $placed_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $fulfilled_at
 * @property string|null $fulfilment_blocked_reason
 * @property array<string, mixed>|null $metadata
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use GuardsPaidOrderRecord, HasFactory, LogsActivity;

    /**
     * Nothing is mass assignable.
     *
     * Every column is either a frozen commercial figure computed on the
     * server, or lifecycle state written by the order service. None may come
     * from request input: the browser names a product or an auction, never a
     * price.
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
            'source' => OrderSource::class,
            'status' => OrderStatus::class,
            'pricing_snapshot' => 'array',
            'metadata' => 'array',
            'holds_reservation' => 'boolean',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'delivery_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'discount_credits' => 'integer',
            'payment_due_at' => 'datetime',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    // --------------------------------------------------------------- Money

    public function subtotal(): Money
    {
        return Money::fromMinor($this->subtotal_minor, $this->currency);
    }

    /**
     * The cedis consumed bid credits took off. Never the credits themselves.
     */
    public function discount(): Money
    {
        return Money::fromMinor($this->discount_minor, $this->currency);
    }

    /**
     * What the customer was charged to have this delivered.
     *
     * Named `deliveryFee` rather than `delivery`, which is the package. One
     * model cannot have "delivery" meaning both a charge and a physical thing
     * without somebody eventually reading the wrong one.
     */
    public function deliveryFee(): Money
    {
        return Money::fromMinor($this->delivery_minor, $this->currency);
    }

    public function tax(): Money
    {
        return Money::fromMinor($this->tax_minor, $this->currency);
    }

    /**
     * What the customer owes, and the only figure a provider is ever asked for.
     */
    public function total(): Money
    {
        return Money::fromMinor($this->total_minor, $this->currency);
    }

    /**
     * The full derivation as it stood at checkout.
     *
     * Rebuilt from the stored snapshot, never recomputed from the product or
     * the auction, either of which may have moved on since.
     */
    public function pricing(): CheckoutPricing
    {
        return CheckoutPricing::fromArray($this->pricing_snapshot);
    }

    // --------------------------------------------------------------- State

    public function isPaid(): bool
    {
        return $this->status->isPaid();
    }

    public function isAwaitingPayment(): bool
    {
        return $this->status->acceptsPayment();
    }

    /**
     * Whether the checkout window has closed.
     *
     * Server time only. A browser's clock has no say in whether a customer
     * may still pay.
     */
    public function hasExpired(?Carbon $now = null): bool
    {
        if ($this->payment_due_at === null) {
            return false;
        }

        return ($now ?? Carbon::now())->greaterThanOrEqualTo($this->payment_due_at);
    }

    /**
     * Whether this order is still payable right now.
     */
    public function isPayable(?Carbon $now = null): bool
    {
        return $this->status->acceptsPayment() && ! $this->hasExpired($now);
    }

    /**
     * Whether a payment succeeded but the order could not be completed.
     *
     * The money is real and recorded. This says the platform owes the customer
     * something and a human has to decide what -- it is a work queue, not a
     * failure to be swallowed.
     */
    public function isFulfilmentBlocked(): bool
    {
        return $this->fulfilment_blocked_reason !== null;
    }

    /**
     * Whether any money has provably gone back on this order.
     *
     * Succeeded refunds only. An attempt the provider is still deciding about
     * has returned nothing yet, and a customer told otherwise would be being
     * told something that is not true.
     *
     * Derived rather than cached. A stored total would be a projection needing
     * its own guard and its own drift problem, for a sum that is almost always
     * over a single row.
     */
    public function hasRefund(): bool
    {
        return $this->refunds()->where('status', RefundStatus::Succeeded)->exists();
    }

    /**
     * Whether a refund is on its way but not yet settled.
     */
    public function hasRefundInProgress(): bool
    {
        return $this->refunds()
            ->whereIn('status', [RefundStatus::Pending, RefundStatus::Processing])
            ->exists();
    }

    /**
     * Whether this order is waiting for somebody to decide what is owed.
     *
     * The administrative queue: a payment succeeded, nothing could be
     * delivered against it, and no refund has been started. Stage 8 created
     * this situation deliberately and left it for a person; this is that
     * person's list.
     */
    public function needsRecovery(): bool
    {
        return $this->isFulfilmentBlocked() && ! $this->hasRefund() && ! $this->hasRefundInProgress();
    }

    public function endedAnAuction(): bool
    {
        return $this->source === OrderSource::BuyNow && $this->auction_id !== null;
    }

    // -------------------------------------------------------- Relationships

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /**
     * The attempt that actually paid for this order, if one did.
     *
     * @return HasOne<OrderPayment, $this>
     */
    public function successfulPayment(): HasOne
    {
        return $this->hasOne(OrderPayment::class)->where('status', OrderPaymentStatus::Success);
    }

    /**
     * The package this order is being sent in.
     *
     * One per order, created when a payment is verified and never before --
     * an abandoned checkout has nothing to deliver.
     *
     * @return HasOne<Delivery, $this>
     */
    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    /**
     * The address book entry chosen at checkout, if one was.
     *
     * A pointer, and only that. Where the package actually went is the copy
     * frozen on the delivery, so a customer tidying their address book cannot
     * change the history of an order.
     *
     * @return BelongsTo<Address, $this>
     */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    /**
     * Money returned against this order.
     *
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return HasMany<OrderTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(OrderTransition::class);
    }

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * The bid that won, on a settlement order.
     *
     * Evidence of what was won with -- never of what is owed, which is the
     * auction's settlement amount and has nothing to do with the bid.
     *
     * @return BelongsTo<Bid, $this>
     */
    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(Bid::class, 'winning_bid_id');
    }

    /**
     * The single product this order is for.
     *
     * The schema supports several items per order; every path in this stage
     * creates exactly one, because both acquisition routes buy one thing.
     */
    public function item(): ?OrderItem
    {
        return $this->items->first() ?? $this->items()->first();
    }

    // -------------------------------------------------------------- Scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingPayment(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::PendingPayment);
    }

    /**
     * Checkouts whose window has closed and which are still unpaid.
     *
     * The work queue for the expiry sweep, and the reason an abandoned
     * checkout cannot hold stock indefinitely.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueToExpire(Builder $query): Builder
    {
        return $query->awaitingPayment()
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBlocked(Builder $query): Builder
    {
        return $query->whereNotNull('fulfilment_blocked_reason');
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    // ----------------------------------------------------------- Audit log

    public function getActivitylogOptions(): LogOptions
    {
        // Narrow on purpose. The transitions table and the payment attempts
        // carry their own evidence; duplicating the amounts here would store
        // the same facts twice without making them any more useful.
        return LogOptions::defaults()
            ->useLogName('order')
            ->logOnly(['status', 'paid_at', 'fulfilled_at', 'cancelled_at', 'fulfilment_blocked_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Order [{$this->order_number}] was {$eventName}";
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryStatus;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One physical package, and where it has got to.
 *
 * A DELIVERY IS NOT AN ORDER, A PAYMENT OR A REFUND. It describes a box
 * moving. The order stays `Processing` throughout `Preparing`,
 * `ReadyForDispatch`, `Dispatched` and `OutForDelivery`, because commercially
 * nothing has changed -- the platform was paid and is getting the item to the
 * customer. Only `Delivered` completes the order, and it does so through the
 * order's own lifecycle rather than by writing its status here.
 *
 * WHAT IT CANNOT DO, and there is no method here for any of it: mark an order
 * paid, create or alter a payment, refund anything, move a credit, post an
 * inventory movement, or change who won an auction. A member of staff carrying
 * a box is not making a statement about money, and the code is arranged so
 * that they cannot accidentally make one.
 *
 * THE ADDRESS IS A COPY, NOT A REFERENCE. It is taken from the customer's
 * address book when the delivery is created and frozen by a database trigger
 * the moment anybody starts handling the package. A customer who moves house
 * cannot redirect something already in transit, and an old order still says
 * where it actually went.
 *
 * NO MONEY AND NO CREDITS. What delivery cost was decided at checkout and is
 * frozen on the order; there is no amount column here, deliberately, because
 * an unused one is an invitation to build a shipping pricing engine by
 * accident.
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property string $reference
 * @property DeliveryStatus $status
 * @property string|null $recipient_name
 * @property string|null $recipient_phone
 * @property string|null $address_line
 * @property string|null $area
 * @property string|null $city
 * @property string|null $region
 * @property string|null $digital_address
 * @property string|null $landmark
 * @property string|null $instructions
 * @property int|null $source_address_id
 * @property string|null $carrier
 * @property string|null $tracking_reference
 * @property string|null $staff_notes
 * @property string|null $received_by
 * @property string|null $delivery_note
 * @property DeliveryFailureReason|null $failure_reason
 * @property string|null $failure_note
 * @property int $attempts
 * @property Carbon|null $prepared_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $out_for_delivery_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory, LogsActivity;

    /**
     * Nothing is mass assignable.
     *
     * Status especially. It is written by exactly one service, which checks
     * the move, holds the locks and records the history. A fillable status is
     * how a browser ends up setting one.
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
            'status' => DeliveryStatus::class,
            'failure_reason' => DeliveryFailureReason::class,
            'attempts' => 'integer',
            'metadata' => 'array',
            'prepared_at' => 'datetime',
            'ready_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'out_for_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------- Address

    /**
     * Whether this package has somewhere to go.
     *
     * The condition for leaving `Pending`. An auction winner's order is
     * created by the closing sweep, when nobody is there to choose an address,
     * so a delivery legitimately begins without one -- and cannot be worked on
     * until it has one.
     */
    public function hasAddress(): bool
    {
        return $this->recipient_name !== null
            && $this->recipient_phone !== null
            && $this->address_line !== null
            && $this->city !== null;
    }

    /**
     * Whether the address may still be set or corrected.
     *
     * Only while nobody has started. The database trigger enforces the same
     * rule; this is so a screen can avoid offering a control that would fail.
     */
    public function addressIsEditable(): bool
    {
        return $this->status === DeliveryStatus::Pending;
    }

    /**
     * The address on one line.
     */
    public function addressSummary(): string
    {
        return implode(', ', array_filter([
            $this->address_line,
            $this->area,
            $this->city,
            $this->region,
        ]));
    }

    // ---------------------------------------------------------------- State

    public function isDelivered(): bool
    {
        return $this->status === DeliveryStatus::Delivered;
    }

    public function hasFailed(): bool
    {
        return $this->status === DeliveryStatus::DeliveryFailed;
    }

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }

    /**
     * Whether staff are blocked on the customer rather than on themselves.
     *
     * A pending delivery with nowhere to go is not the warehouse's problem,
     * and the queue says so rather than showing it as work.
     */
    public function isAwaitingAddress(): bool
    {
        return $this->status === DeliveryStatus::Pending && ! $this->hasAddress();
    }

    /**
     * When each step actually happened.
     *
     * Only steps that occurred appear. A future state is never given a time,
     * because a tracking page showing a completed step that has not happened
     * is worse than showing nothing.
     *
     * @return array<string, Carbon|null>
     */
    public function stepTimestamps(): array
    {
        return [
            DeliveryStatus::Pending->value => $this->created_at,
            DeliveryStatus::Preparing->value => $this->prepared_at,
            DeliveryStatus::ReadyForDispatch->value => $this->ready_at,
            DeliveryStatus::Dispatched->value => $this->dispatched_at,
            DeliveryStatus::OutForDelivery->value => $this->out_for_delivery_at,
            DeliveryStatus::Delivered->value => $this->delivered_at,
        ];
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The book entry this was copied from, if it is still there.
     *
     * For support to trace, and nothing more. The copy is what governs.
     *
     * @return BelongsTo<Address, $this>
     */
    public function sourceAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'source_address_id');
    }

    /**
     * @return HasMany<DeliveryTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(DeliveryTransition::class);
    }

    // -------------------------------------------------------------- Scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            DeliveryStatus::Delivered,
            DeliveryStatus::Cancelled,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DeliveryStatus::DeliveryFailed);
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    // ----------------------------------------------------------- Audit log

    public function getActivitylogOptions(): LogOptions
    {
        // Narrow on purpose. The transitions table carries who moved what and
        // when; duplicating it here would store the same facts twice without
        // making them any more useful.
        return LogOptions::defaults()
            ->useLogName('delivery')
            ->logOnly(['status', 'carrier', 'tracking_reference'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Delivery [{$this->reference}] was {$eventName}";
    }
}

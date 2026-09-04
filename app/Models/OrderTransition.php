<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded change in an order's lifecycle.
 *
 * Written by the order service inside the same transaction as the change it
 * describes, so the history cannot disagree with the state. Append-only: a
 * database trigger refuses updates.
 *
 * When a customer asks why their order is where it is -- or when it was paid,
 * or who cancelled it -- this is the record that answers.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property string|null $reason
 * @property int|null $caused_by
 * @property Carbon $created_at
 */
class OrderTransition extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'reason',
        'caused_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

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
    public function causedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caused_by');
    }
}

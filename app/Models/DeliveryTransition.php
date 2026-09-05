<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded move of one package.
 *
 * Written by the delivery service inside the same transaction as the change it
 * describes, so the history cannot disagree with the state. Append-only:
 * database triggers refuse both updates and deletes.
 *
 * THIS MATTERS MORE HERE THAN ANYWHERE ELSE ON THE PLATFORM. Every other
 * lifecycle has something external to check against -- a provider to ask about
 * a payment, a ledger to recompute a balance. A manual delivery has nothing.
 * When a customer says nobody came, this table is the only record of who said
 * otherwise and when, so it is written for every move and never edited.
 *
 * @property int $id
 * @property int $delivery_id
 * @property DeliveryStatus|null $from_status
 * @property DeliveryStatus $to_status
 * @property DeliveryFailureReason|null $reason_code
 * @property string|null $note
 * @property int|null $caused_by
 * @property Carbon $created_at
 */
class DeliveryTransition extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'delivery_id',
        'from_status',
        'to_status',
        'reason_code',
        'note',
        'caused_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => DeliveryStatus::class,
            'to_status' => DeliveryStatus::class,
            'reason_code' => DeliveryFailureReason::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caused_by');
    }
}

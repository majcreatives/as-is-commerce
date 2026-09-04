<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuctionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded change in an auction's lifecycle.
 *
 * Written by the lifecycle service inside the same transaction as the change
 * it describes, so the history cannot disagree with the state. Append-only: a
 * database trigger refuses updates.
 *
 * When a bidder asks why an auction ended when it did, or whether it was
 * stopped by an administrator rather than by the clock, this is the record
 * that answers.
 *
 * @property int $id
 * @property int $auction_id
 * @property AuctionStatus|null $from_status
 * @property AuctionStatus $to_status
 * @property string|null $reason
 * @property int|null $caused_by
 * @property Carbon $created_at
 */
class AuctionTransition extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'auction_id',
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
            'from_status' => AuctionStatus::class,
            'to_status' => AuctionStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caused_by');
    }
}

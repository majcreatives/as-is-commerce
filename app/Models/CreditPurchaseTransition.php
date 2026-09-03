<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditPurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recorded move in a purchase's lifecycle.
 *
 * When a customer disputes what happened to a payment, this is the record that
 * answers it: what the purchase was, what it became, when, and at whose hand.
 *
 * @property int $id
 * @property int $credit_purchase_id
 * @property CreditPurchaseStatus|null $from_status
 * @property CreditPurchaseStatus $to_status
 * @property string|null $reason
 * @property int|null $caused_by
 * @property Carbon $created_at
 */
class CreditPurchaseTransition extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => CreditPurchaseStatus::class,
            'to_status' => CreditPurchaseStatus::class,
            'created_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caused_by');
    }
}

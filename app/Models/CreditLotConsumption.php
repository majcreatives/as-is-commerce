<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsAppendOnly;
use Database\Factories\CreditLotConsumptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records exactly which lot a debit drew credits from.
 *
 * Without this, a debit would say only that credits left the wallet, and the
 * system could not later answer whether a refund should return promotional or
 * purchased credits, or which lot an expiry should be charged against.
 *
 * Append-only, like the ledger itself.
 *
 * @property int $id
 * @property int $credit_lot_id
 * @property int $credit_transaction_id
 * @property int $amount Always positive: the quantity taken from the lot.
 * @property Carbon $created_at
 */
class CreditLotConsumption extends Model
{
    /** @use HasFactory<CreditLotConsumptionFactory> */
    use HasFactory, IsAppendOnly;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CreditLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(CreditLot::class, 'credit_lot_id');
    }

    /**
     * @return BelongsTo<CreditTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class, 'credit_transaction_id');
    }
}

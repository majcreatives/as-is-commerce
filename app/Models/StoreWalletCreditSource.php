<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use App\Enums\CreditLotSource;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One credit lot's contribution to one Store Wallet issuance.
 *
 * This is the row that makes the model auditable rather than merely plausible.
 * An issuance of 2.80 is not a number somebody arrived at; it is these lines
 * added up:
 *
 *     lot #41  20 credits  from 10.00 / 100 credits   2.00
 *     lot #58  10 credits  from 40.00 / 500 credits   0.80
 *     lot #63  15 credits  referral, cost nothing     0.00
 *
 * The lot's totals are copied here rather than only referenced, so the
 * arithmetic can be rechecked from this table alone even though the lot's own
 * figures are themselves frozen. Two independent records of the same fact is
 * the point: if they ever disagree, that is a discrepancy worth seeing.
 *
 * Free lots are recorded at zero rather than omitted. A breakdown that quietly
 * dropped them could not answer why 45 consumed credits produced the value of
 * 30.
 *
 * @property int $id
 * @property int $store_wallet_transaction_id
 * @property int $credit_lot_id
 * @property CreditLotSource $source_type
 * @property int $credits
 * @property int $lot_acquisition_amount_minor
 * @property int $lot_original_amount
 * @property int $amount_minor
 * @property int $remainder_numerator
 * @property string $currency
 * @property Carbon $created_at
 */
class StoreWalletCreditSource extends Model
{
    use IsAppendOnly;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => CreditLotSource::class,
            'credits' => 'integer',
            'lot_acquisition_amount_minor' => 'integer',
            'lot_original_amount' => 'integer',
            'amount_minor' => 'integer',
            'remainder_numerator' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return Money::fromMinor($this->amount_minor, $this->currency);
    }

    /**
     * @return BelongsTo<StoreWalletTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StoreWalletTransaction::class, 'store_wallet_transaction_id');
    }

    /**
     * @return BelongsTo<CreditLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(CreditLot::class, 'credit_lot_id');
    }
}

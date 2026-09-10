<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditLotSource;
use Database\Factories\CreditLotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A batch of credits that entered the wallet together.
 *
 * Credits are not fungible: promotional credits may expire and may be
 * non-refundable, while purchased ones represent money the customer actually
 * paid. Tracking them in lots is what lets the system answer "which credits
 * were spent" -- a question a single flattened balance cannot answer, and one
 * that expiry and refunds both depend on.
 *
 * Unlike ledger rows, a lot is mutable in exactly one respect: its
 * `remaining_amount` falls as credits are consumed. Every such change is
 * accompanied by a consumption record and a ledger row, written in the same
 * transaction, so the movement is still fully evidenced.
 *
 * @property int $id
 * @property int $credit_wallet_id
 * @property int $credit_transaction_id
 * @property CreditLotSource $source_type
 * @property int $original_amount
 * @property int $remaining_amount
 * @property int|null $acquisition_amount_minor
 * @property string|null $acquisition_currency
 * @property Carbon|null $expires_at
 * @property Carbon|null $exhausted_at
 */
class CreditLot extends Model
{
    /** @use HasFactory<CreditLotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => CreditLotSource::class,
            'original_amount' => 'integer',
            'remaining_amount' => 'integer',
            'acquisition_amount_minor' => 'integer',
            'expires_at' => 'datetime',
            'exhausted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CreditWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'credit_wallet_id');
    }

    /**
     * The transaction that created this lot.
     *
     * @return BelongsTo<CreditTransaction, $this>
     */
    public function originTransaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class, 'credit_transaction_id');
    }

    /**
     * @return HasMany<CreditLotConsumption, $this>
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(CreditLotConsumption::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->remaining_amount === 0;
    }

    public function isSpendable(): bool
    {
        return ! $this->isExhausted() && ! $this->isExpired();
    }

    public function consumedAmount(): int
    {
        return $this->original_amount - $this->remaining_amount;
    }

    /**
     * Lots holding credits that can be spent right now.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSpendable(Builder $query): Builder
    {
        return $query
            ->where('remaining_amount', '>', 0)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Lots past their expiry that still hold credits not yet written off.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiredWithRemainder(Builder $query): Builder
    {
        return $query
            ->where('remaining_amount', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}

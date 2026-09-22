<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Enums\CreditTransactionType;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One immutable movement of bidding credits.
 *
 * This is the authoritative financial record. Rows are never updated and
 * never deleted -- by this model, by any other code, or by a direct SQL
 * session, since database triggers refuse both. A mistake is corrected by
 * posting a compensating entry, so the history of what was believed and when
 * survives intact.
 *
 * @property int $id
 * @property int $credit_wallet_id
 * @property CreditTransactionType $type
 * @property int $amount Signed: positive adds credits, negative removes them.
 * @property int $balance_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $idempotency_key
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory, IsAppendOnly;

    /**
     * An append-only table has no updated_at, because nothing is updated.
     */
    public const UPDATED_AT = null;

    /**
     * Written by the ledger service, never mass-assigned from a request.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
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
     * The business event that caused this movement -- a payment, a bid, an
     * adjustment. Polymorphic because those live in different tables.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Which lots this transaction drew credits from. Empty for transactions
     * that add credits rather than consume them.
     *
     * @return HasMany<CreditLotConsumption, $this>
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(CreditLotConsumption::class);
    }

    /**
     * The lot this transaction created, if it created one.
     *
     * @return HasMany<CreditLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(CreditLot::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function isDebit(): bool
    {
        return $this->amount < 0;
    }

    /**
     * Signed amount rendered the way a statement would show it.
     *
     * The bare grouped number (`CreditAmount::formatNumber()`), never the
     * unit word -- every caller shows this in a column already labelled
     * "Credits", where repeating the word on every row would be clutter.
     */
    public function signedAmount(): string
    {
        $amount = CreditAmount::fromSubcredits($this->amount);

        return ($amount->isPositive() ? '+' : '').$amount->formatNumber();
    }
}

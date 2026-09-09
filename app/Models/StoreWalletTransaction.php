<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use App\Enums\StoreWalletTransactionType;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One immutable movement of Store Wallet value.
 *
 * The authoritative record. `store_wallets.balance_minor` is a cache of the
 * running sum of these rows and nothing more, and a trigger refuses any update
 * or delete here, so the history cannot be tidied after the fact.
 *
 * EVERY ROW EXPLAINS ITSELF. `reference` names the business event -- the
 * auction whose loss produced the value, or the order that consumed it -- and
 * an issuance additionally carries {@see StoreWalletCreditSource} rows naming
 * the exact credit lots it came from and what each contributed. That chain is
 * what lets the question "why do I have this?" be answered from records rather
 * than from an administrator's memory.
 *
 * `idempotency_key` is unique and is how a repeated event produces one
 * movement. For an auction loss it is derived from the auction and the user,
 * so a re-run sweep, a retried worker or two concurrent closures converge on
 * the single row that already exists.
 *
 * @property int $id
 * @property int $store_wallet_id
 * @property StoreWalletTransactionType $type
 * @property int $amount_minor Signed: positive adds, negative removes.
 * @property int $balance_after_minor
 * @property string $currency
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $idempotency_key
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class StoreWalletTransaction extends Model
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
            'type' => StoreWalletTransactionType::class,
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The signed amount as an exact Money value.
     */
    public function amount(): Money
    {
        return Money::fromMinor($this->amount_minor, $this->currency);
    }

    /**
     * The magnitude, for display beside a direction indicator.
     */
    public function absoluteAmount(): Money
    {
        return Money::fromMinor(abs($this->amount_minor), $this->currency);
    }

    public function balanceAfter(): Money
    {
        return Money::fromMinor($this->balance_after_minor, $this->currency);
    }

    public function isCredit(): bool
    {
        return $this->amount_minor > 0;
    }

    /**
     * @return BelongsTo<StoreWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(StoreWallet::class, 'store_wallet_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Which credit lots produced this value, and how much each contributed.
     *
     * Empty for anything but an issuance: an order debit consumes wallet
     * value, which has no lots of its own.
     *
     * @return HasMany<StoreWalletCreditSource, $this>
     */
    public function creditSources(): HasMany
    {
        return $this->hasMany(StoreWalletCreditSource::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, StoreWalletTransactionType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}

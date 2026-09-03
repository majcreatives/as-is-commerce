<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use App\Enums\CashTransactionType;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\CashTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One immutable movement of real money.
 *
 * Amounts are integer minor units, exposed as {@see Money} so no caller ever
 * has to divide by 100 and risk a float.
 *
 * @property int $id
 * @property int $cash_wallet_id
 * @property CashTransactionType $type
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
class CashTransaction extends Model
{
    /** @use HasFactory<CashTransactionFactory> */
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
            'type' => CashTransactionType::class,
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return Money::fromMinor($this->amount_minor, $this->currency);
    }

    public function balanceAfter(): Money
    {
        return Money::fromMinor($this->balance_after_minor, $this->currency);
    }

    /**
     * @return BelongsTo<CashWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CashWallet::class, 'cash_wallet_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Signed amount rendered the way a statement would show it.
     */
    public function signedAmount(): string
    {
        return ($this->amount_minor > 0 ? '+' : '-')
            .Money::fromMinor(abs($this->amount_minor), $this->currency)->format();
    }
}

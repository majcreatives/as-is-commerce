<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Credit\Services\CreditLedgerReconciler;
use App\Models\Concerns\GuardsMaterializedBalance;
use Database\Factories\CreditWalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's bidding-credit wallet.
 *
 * `balance` is a materialized projection of the ledger, not the truth. It
 * exists so a balance can be read without summing every transaction, and it
 * is written only inside a ledger service, in the same transaction as the
 * row that justifies it. {@see CreditLedgerReconciler}
 * proves the two agree.
 *
 * @property int $id
 * @property int $user_id
 * @property int $balance
 */
class CreditWallet extends Model
{
    /** @use HasFactory<CreditWalletFactory> */
    use GuardsMaterializedBalance, HasFactory;

    /**
     * Only the owner is assignable. The balance never is.
     *
     * @var list<string>
     */
    protected $fillable = ['user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
        ];
    }

    public function balanceColumn(): string
    {
        return 'balance';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CreditTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * @return HasMany<CreditLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(CreditLot::class);
    }

    /**
     * Lots that still hold spendable credits and have not expired.
     *
     * @return HasMany<CreditLot, $this>
     */
    public function spendableLots(): HasMany
    {
        return $this->lots()
            ->where('remaining_amount', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Credits the user can actually spend right now.
     *
     * Computed from the lots rather than read from the balance column,
     * because expired credits still count towards the ledger balance until an
     * expiry transaction is posted, but cannot be spent.
     */
    public function spendableBalance(): int
    {
        return (int) $this->spendableLots()->sum('remaining_amount');
    }

    /**
     * Credits held in lots that have passed their expiry date but have not
     * yet been written off by an expiry transaction.
     */
    public function expiredUnclaimedBalance(): int
    {
        return (int) $this->lots()
            ->where('remaining_amount', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->sum('remaining_amount');
    }
}

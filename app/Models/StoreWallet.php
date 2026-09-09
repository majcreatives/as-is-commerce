<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use App\Models\Concerns\GuardsMaterializedBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's Store Wallet: non-withdrawable purchasing power inside the shop.
 *
 * THE THIRD WALLET, AND DELIBERATELY NOT EITHER OF THE OTHER TWO.
 *
 *   CreditWallet   bidding credits. A count. Spent on bids, gone when spent.
 *   CashWallet     real money the platform received. GHS.
 *   StoreWallet    what a losing bidder's purchased credits actually cost,
 *                  returned as purchasing power rather than as money or as
 *                  credits.
 *
 * A separate table rather than a column on either of the others, for the same
 * reason cash and credits are separate: it makes a conversion between them
 * something nobody can write by accident. There is no code path that moves
 * value from here into a credit wallet or out to a bank.
 *
 * WHAT THIS BALANCE MAY DO. Reduce the GHS payable on a fixed-price catalogue
 * order. That is the entire list.
 *
 * WHAT IT MAY NOT DO. Buy bidding credits, become bidding credits, be
 * withdrawn, be transferred to another user, influence a bid, or settle an
 * auction win. Those are not merely unimplemented -- the checkout refuses
 * them and a database CHECK constraint refuses an order that claims one.
 *
 * `balance_minor` is a materialized projection of `store_wallet_transactions`,
 * exactly as the credit and cash balances are projections of their ledgers,
 * and is writable only from inside the ledger service.
 *
 * @property int $id
 * @property int $user_id
 * @property int $balance_minor
 * @property string $currency
 */
class StoreWallet extends Model
{
    use GuardsMaterializedBalance;

    /** @var list<string> */
    protected $fillable = ['user_id', 'currency'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
        ];
    }

    public function balanceColumn(): string
    {
        return 'balance_minor';
    }

    /**
     * The balance as an exact Money value, never a float.
     */
    public function balance(): Money
    {
        return Money::fromMinor($this->balance_minor, $this->currency);
    }

    public function isEmpty(): bool
    {
        return $this->balance_minor <= 0;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<StoreWalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(StoreWalletTransaction::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use App\Models\Concerns\GuardsMaterializedBalance;
use Database\Factories\CashWalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's real-money wallet.
 *
 * Entirely separate from {@see CreditWallet}. Cash is a liability to the
 * customer; credits are a virtual entitlement with their own expiry and
 * refund rules. Keeping them in different tables makes it structurally
 * impossible for a credit movement to settle as a cash one.
 *
 * @property int $id
 * @property int $user_id
 * @property int $balance_minor
 * @property string $currency
 */
class CashWallet extends Model
{
    /** @use HasFactory<CashWalletFactory> */
    use GuardsMaterializedBalance, HasFactory;

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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CashTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }
}

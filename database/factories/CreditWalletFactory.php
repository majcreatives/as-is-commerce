<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreditWallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditWallet>
 */
class CreditWalletFactory extends Factory
{
    protected $model = CreditWallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Balance is deliberately absent: it is not fillable, and a wallet is
        // only ever moved by the ledger. Tests that need a balance post
        // transactions, which is the same path production uses.
        return [
            'user_id' => User::factory(),
        ];
    }
}

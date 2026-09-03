<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CashTransactionType;
use App\Models\CashTransaction;
use App\Models\CashWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashTransaction>
 */
class CashTransactionFactory extends Factory
{
    protected $model = CashTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cash_wallet_id' => CashWallet::factory(),
            'type' => CashTransactionType::Deposit,
            'amount_minor' => 10_000,
            'balance_after_minor' => 10_000,
            'currency' => 'GHS',
        ];
    }
}

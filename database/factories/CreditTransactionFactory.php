<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 *
 * Used only for constraint and immutability tests, which need to attempt
 * writes the ledger service would refuse. Ordinary tests should post through
 * the service so the wallet, lots and ledger stay consistent.
 */
class CreditTransactionFactory extends Factory
{
    protected $model = CreditTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_wallet_id' => CreditWallet::factory(),
            'type' => CreditTransactionType::AdjustmentCredit,
            'amount' => 100,
            'balance_after' => 100,
        ];
    }
}

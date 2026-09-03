<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditLotSource;
use App\Models\CreditLot;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditLot>
 */
class CreditLotFactory extends Factory
{
    protected $model = CreditLot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_wallet_id' => CreditWallet::factory(),
            'credit_transaction_id' => CreditTransaction::factory(),
            'source_type' => CreditLotSource::Purchased,
            'original_amount' => 100,
            'remaining_amount' => 100,
            'expires_at' => null,
        ];
    }
}

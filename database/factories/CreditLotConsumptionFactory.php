<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreditLot;
use App\Models\CreditLotConsumption;
use App\Models\CreditTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditLotConsumption>
 */
class CreditLotConsumptionFactory extends Factory
{
    protected $model = CreditLotConsumption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_lot_id' => CreditLot::factory(),
            'credit_transaction_id' => CreditTransaction::factory(),
            'amount' => 1,
        ];
    }
}

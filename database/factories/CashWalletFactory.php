<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CashWallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashWallet>
 */
class CashWalletFactory extends Factory
{
    protected $model = CashWallet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency' => 'GHS',
        ];
    }
}

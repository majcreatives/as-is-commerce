<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryTransactionType;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 *
 * Used only for constraint and immutability tests, which need to attempt
 * writes the inventory service would refuse. Ordinary tests should move stock
 * through InventoryService so the product projection stays consistent.
 */
class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'type' => InventoryTransactionType::InitialStock,
            'quantity_delta' => 10,
            'stock_on_hand_after' => 10,
            'stock_reserved_after' => 0,
            'reason' => 'Opening stock.',
        ];
    }
}

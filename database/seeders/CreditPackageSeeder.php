<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Shared\Money\Money;
use App\Models\CreditPackage;
use Illuminate\Database\Seeder;

/**
 * Starting credit packages.
 *
 * These are real, conservative starting values -- not demo data -- and they
 * are ordinary configuration: an administrator can reprice, add or withdraw
 * packages without a code change, and doing so never affects a purchase
 * already made.
 *
 * The prices say how much money buys how many credits, and nothing else. They
 * bear no relationship to any product's Buy Now price, and credits do not
 * convert back into money.
 *
 * Safe to run repeatedly: an existing package is left exactly as the
 * administrator last set it.
 */
class CreditPackageSeeder extends Seeder
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'A small pack to try bidding with.',
                'credit_amount' => 100,
                'price' => '10.00',
                'sort_order' => 10,
            ],
            [
                'slug' => 'popular',
                'name' => 'Popular',
                'description' => 'Our most commonly bought pack.',
                'credit_amount' => 500,
                'price' => '45.00',
                'sort_order' => 20,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'For frequent bidders.',
                'credit_amount' => 1_000,
                'price' => '80.00',
                'sort_order' => 30,
            ],
        ];
    }

    public function run(): void
    {
        $currency = 'GHS';

        foreach (self::definitions() as $definition) {
            if (CreditPackage::where('slug', $definition['slug'])->exists()) {
                continue;
            }

            $package = new CreditPackage([
                'name' => $definition['name'],
                'description' => $definition['description'],
                'credit_amount' => $definition['credit_amount'],
                // Parsed from a decimal string into integer pesewas: no float
                // appears anywhere between here and the database.
                'price_minor' => Money::fromDecimalString($definition['price'], $currency)->minor,
                'currency' => $currency,
                'sort_order' => $definition['sort_order'],
            ]);

            $package->slug = $definition['slug'];
            $package->is_active = true;
            $package->save();
        }
    }
}

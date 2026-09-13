<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds only the reference data the application requires to function.
     *
     * No demo users, auctions, balances or transactions are created here.
     * The catalog (categories, brands, products with initial stock) is
     * representative development data, created by CatalogSeeder.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            SettingsSeeder::class,
            AuctionRulesetSeeder::class,
            CreditPackageSeeder::class,
            CatalogSeeder::class,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds only the reference data the application requires to function.
     *
     * No demo users, products, auctions, balances or transactions are created
     * here or anywhere else in this project.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
        ]);
    }
}

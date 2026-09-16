<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An explicit, opt-in permission to run a product through the auction channel.
 *
 * The auction channel is a separate distribution channel with its own gate.
 * A catalogue listing (and a Buy Now price) is not entitlement to be auctioned:
 * publication into the auction channel is a deliberate, recorded act by an
 * administrator, and a product that has not been opted in cannot be auctioned.
 *
 * Defaults to false on purpose. A new product is not auction-eligible until an
 * administrator says so, so a catalogued product can never silently appear in
 * an auction just because it exists.
 *
 * Additive only, matching the Stage 20 rule: nothing about the existing schema
 * is rewritten, and `down()` removes exactly what `up()` added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('auction_eligible')
                ->default(false)
                ->after('status');
        });

        // A boolean that only ever stores 0 or 1, enforced by the database
        // itself so a hand-run statement cannot invent a third state.
        DB::statement('
            ALTER TABLE products
            ADD CONSTRAINT chk_products_auction_eligible
            CHECK (auction_eligible IN (0, 1))
        ');
    }

    public function down(): void
    {
        // MariaDB drops named CHECKs through DROP CONSTRAINT, not DROP CHECK.
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_auction_eligible');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('auction_eligible');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's shopping cart: what they intend to buy, before any order exists.
 *
 * A cart is NOT an order, a payment, an auction bid, or an inventory movement.
 * It holds no money and reserves no stock. Editing a cart writes only these two
 * tables; nothing financial moves, nothing is set aside for the customer. This
 * is deliberate: a cart abandoned halfway cannot hold stock hostage, and a
 * cart is not a financial record, so it has no ledger and no immutability.
 *
 * THE CART IS PER CUSTOMER. One row in `carts` per user, enforced by the
 * unique key, so there is never a question of which basket is the real one.
 *
 * THE LINES ARE THE CART. Each line names a product and a quantity. Products
 * are restricted from deletion -- an order line may already point at one, and
 * a cart line is the same shape of reference. The quantity must be positive:
 * a zero or negative line would be a line that is not a line.
 *
 * RESERVATION HAPPENS AT PLACEMENT, NOT HERE. When the customer submits the
 * cart, one atomic placement validates every line against the inventory ledger
 * and reserves every line's quantity -- and if any single line cannot be
 * satisfied, no line is reserved and no order is created. Nothing in these
 * tables anticipates that decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();

            // One cart per customer. Restricted, matching the approved scope:
            // a cart line can point at an ordered product, and the reference
            // must not be destroyed out from under historical references.
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            // The cart owns its lines.
            $table->foreignId('cart_id')
                ->constrained('carts')
                ->cascadeOnDelete();

            // Restricted: a product that has been or could be ordered cannot
            // be deleted out from under its references.
            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            // A product appears once per cart: two rows for the same product
            // would be two opinions of how many the customer wants.
            $table->unique(['cart_id', 'product_id']);

            $table->index('product_id');
        });

        // A quantity is a number and a positive one. Repeated below the
        // application because a hand-run statement can bypass any service.
        DB::statement(<<<'SQL'
            ALTER TABLE cart_items
            ADD CONSTRAINT chk_cart_items_quantity_positive CHECK (quantity > 0)
        SQL);
    }

    public function down(): void
    {
        // MariaDB drops named CHECKs through DROP CONSTRAINT, not DROP CHECK.
        DB::statement('ALTER TABLE cart_items DROP CONSTRAINT chk_cart_items_quantity_positive');

        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};

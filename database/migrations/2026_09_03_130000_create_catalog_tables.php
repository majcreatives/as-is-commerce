<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog: categories, brands and products.
 *
 * Inventory is platform-owned. There is no seller, vendor or merchant column
 * anywhere here, and none should be added without a deliberate marketplace
 * stage.
 *
 * A product's Buy Now price is its own figure, in integer pesewas, with no
 * database relationship to credit packages, credit balances or bid amounts.
 * Nothing joins this table to the credit ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();

            // Self-referencing, so Electronics can contain Phones. Restricted
            // on delete: a category with children cannot be removed out from
            // under them.
            $table->foreignId('parent_id')->nullable()
                ->constrained('categories')->restrictOnDelete();

            $table->string('status', 20)->default('active');
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'sort_order']);
            $table->index('parent_id');
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();

            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // The business identifier, used for inventory work. Human-readable
            // and stable, so operations never has to quote a database id.
            $table->string('sku', 64)->unique();

            $table->string('slug', 200)->unique();
            $table->string('name', 200);
            $table->string('short_description', 300)->nullable();
            $table->text('description')->nullable();

            // Restricted: a category holding products cannot be deleted, so a
            // product can never be orphaned from its place in the catalog.
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();

            // Optional: not everything has a conventional brand.
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();

            $table->string('condition', 20);
            $table->string('status', 20)->default('draft');

            // The product's own price, in integer minor units (pesewas).
            // Independent of every credit figure in the system.
            $table->unsignedBigInteger('buy_now_price_minor');
            $table->char('currency', 3)->default('GHS');

            // Materialized projections of the inventory ledger. Written only
            // by the inventory service, inside the same transaction as the
            // movement that justifies them.
            $table->unsignedBigInteger('stock_on_hand')->default(0);
            $table->unsignedBigInteger('stock_reserved')->default(0);

            // A single image path for now. Product media proper belongs to a
            // later stage; this avoids pulling in a media library the
            // application does not otherwise need.
            $table->string('image_path', 500)->nullable();

            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Supports the public catalog: visible products, newest first.
            $table->index(['status', 'published_at']);
            $table->index(['status', 'category_id']);
            $table->index(['status', 'brand_id']);
            $table->index(['status', 'condition']);
            $table->index('buy_now_price_minor');
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();

            // Restricted, not cascading. InnoDB does not fire triggers for
            // foreign key actions, so a cascade would delete the audit trail
            // straight past the append-only guard. Restricting means a product
            // with stock history cannot be deleted at all -- archive it, which
            // is what the status lifecycle is for.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->string('type', 30);

            // Signed on purpose. A movement may legitimately be negative even
            // though the resulting stock may not be, so this column is not
            // unsigned.
            $table->bigInteger('quantity_delta');

            // The resulting state, stored so the ledger is verifiable by a
            // single scan rather than by replaying every prior movement.
            $table->unsignedBigInteger('stock_on_hand_after');
            $table->unsignedBigInteger('stock_reserved_after');

            $table->string('reason', 500)->nullable();

            // What caused the movement -- an order, a return. Polymorphic
            // because those tables do not exist yet.
            $table->nullableMorphs('reference');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Created only: an audit trail has nothing to update.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['product_id', 'type']);
        });

        // ---------------------------------------------------------------
        // Constraints. The application enforces all of this too, but a
        // service can be bypassed by a console command or a hand-run
        // statement. The database cannot.
        // ---------------------------------------------------------------

        // Self-parenting and longer cycles are prevented in the application:
        // MySQL rejects a CHECK constraint that refers to an auto-increment
        // column, and a constraint could not catch a multi-step loop anyway.
        // Category::ancestry() is also cycle-safe, so bad data cannot hang a
        // page even if it somehow arrived.
        DB::statement(<<<'SQL'
            ALTER TABLE categories
            ADD CONSTRAINT chk_categories_status CHECK (status IN ('active','inactive','archived'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE brands
            ADD CONSTRAINT chk_brands_status CHECK (status IN ('active','inactive','archived'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD CONSTRAINT chk_products_status CHECK (
                status IN ('draft','active','inactive','out_of_stock','archived')
            ),
            ADD CONSTRAINT chk_products_condition CHECK (
                `condition` IN ('new','used','refurbished')
            ),
            ADD CONSTRAINT chk_products_price_positive CHECK (buy_now_price_minor > 0),
            ADD CONSTRAINT chk_products_reserved_within_stock CHECK (
                stock_reserved <= stock_on_hand
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_transactions
            ADD CONSTRAINT chk_inventory_delta_nonzero CHECK (quantity_delta <> 0),
            ADD CONSTRAINT chk_inventory_reserved_within_stock CHECK (
                stock_reserved_after <= stock_on_hand_after
            )
        SQL);

        // The inventory trail is append-only, for the same reason the credit
        // and cash ledgers are: a stock history that can be edited is not
        // evidence of anything.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER inventory_transactions_no_update
            BEFORE UPDATE ON inventory_transactions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Inventory history is append-only: post a correcting adjustment instead.';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER inventory_transactions_no_delete
            BEFORE DELETE ON inventory_transactions
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Inventory history is append-only: post a correcting adjustment instead.';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_transactions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_transactions_no_delete');

        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};

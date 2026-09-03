<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer can buy: a fixed number of credits for a fixed price in GHS.
 *
 * The price defines how much money buys how many credits, and nothing more.
 * It has no relationship to any product's Buy Now price, and credits do not
 * convert back into money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_packages', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('description', 500)->nullable();

            // Whole credits. Never fractional.
            $table->unsignedBigInteger('credit_amount');

            // Integer minor units (pesewas), never float.
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3)->default('GHS');

            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Supports the storefront query: active packages in display order.
            $table->index(['is_active', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE credit_packages
            ADD CONSTRAINT chk_credit_packages_credits_positive CHECK (credit_amount > 0),
            ADD CONSTRAINT chk_credit_packages_price_positive CHECK (price_minor > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packages');
    }
};

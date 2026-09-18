<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's image gallery.
 *
 * The catalog has always carried a single `image_path` against the product.
 * That column stays: it is the fallback a listing uses before any gallery
 * exists. This table holds the gallery proper -- several images per product,
 * the first of which is the featured one shown on cards and auctions.
 *
 * A product is archived rather than deleted, so the foreign key restricts
 * with the same reasoning as the product's other children: a product with
 * images cannot be removed out from under its pictures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            // Restricted, not cascading, for the same reason the product and
            // its stock history are: cascade deletes past a deliberately
            // frozen record are how evidence disappears. A product with images
            // is archived, not deleted.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // The stored path, relative to the public disk (products/{id}/...).
            $table->string('image_path', 500);

            // Position within the gallery. Deliberately not unique against the
            // product: a reorder swaps positions inside a transaction, and a
            // transient duplicate mid-swap is harmless because reads order by
            // position then id and the service rewrites the order atomically.
            $table->unsignedInteger('position')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Gallery reads sort by position; a featured image is simply the
            // first row.
            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blog categories.
 *
 * Deliberately separate from the catalog's `categories`: a blog post belongs
 * to a place in the content tree, not a place in the shop, and mixing the two
 * would let a catalogue rename drag a blog taxonomy along with it.
 *
 * They follow the same shape as the catalogue categories -- name, slug,
 * description, display order, an archiveable status -- because retiring a
 * category means archiving it here too. Nothing is ever hard-deleted out from
 * under a post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE blog_categories
            ADD CONSTRAINT chk_blog_categories_status CHECK (status IN ('active','inactive','archived'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE blog_categories DROP CONSTRAINT chk_blog_categories_status');

        Schema::dropIfExists('blog_categories');
    }
};

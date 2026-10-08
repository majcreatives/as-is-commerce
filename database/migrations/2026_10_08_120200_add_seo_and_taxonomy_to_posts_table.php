<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional single category, and optional search-engine metadata, on post.
 *
 * Every SEO column is nullable and none of them are ever invented on a page:
 * the public output falls back to the post's own title and excerpt when an
 * editor has not supplied a dedicated meta title or meta description. The
 * keywords are the primary one plus a JSON list of secondary ones, used for
 * the meta keywords tag and the BlogPosting structured data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()
                ->after('body')
                ->constrained('blog_categories')->nullOnDelete();

            $table->string('meta_title', 150)->nullable()->after('image_path');
            $table->string('meta_description', 300)->nullable()->after('meta_title');
            $table->string('primary_keyword', 100)->nullable()->after('meta_description');
            $table->json('secondary_keywords')->nullable()->after('primary_keyword');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['meta_title', 'meta_description', 'primary_keyword', 'secondary_keywords']);
        });
    }
};

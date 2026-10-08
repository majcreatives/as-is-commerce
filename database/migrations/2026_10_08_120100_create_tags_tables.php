<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blog tags, and the post<->tag pivot.
 *
 * Tags are free-form but governed: an administrator creates them in the
 * taxonomy screen and picks them in the post form, so a tag never appears
 * because a typo invented it. Like categories they can be archived rather than
 * deleted, and the pivot cleans up after itself either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();

            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->primary(['post_id', 'tag_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tags
            ADD CONSTRAINT chk_tags_status CHECK (status IN ('active','inactive','archived'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tags DROP CONSTRAINT chk_tags_status');

        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('tags');
    }
};

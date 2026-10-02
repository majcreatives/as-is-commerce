<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partners and success stories: the two pieces of company content a
 * non-technical administrator publishes from the admin area.
 *
 * DELIBERATELY NOT A CONTENT SYSTEM. There is no posts table, no taxonomy, no
 * draft/revision model and no editor. Each table holds one narrow kind of record
 * -- a partner, or a story -- with the few fields that kind actually needs, and
 * nothing else. AGENTS.md §110 warns against building a CMS ahead of approval;
 * this is the smallest thing that lets an administrator publish these two
 * things without a deploy.
 *
 * `active` IS THE PUBLISH FLAG. An unpublished row is a draft, and publishing is
 * setting one boolean. There is no separate status enum, because there is only
 * one decision to make: is this on the public site or not.
 *
 * `sort_order` IS ADMINISTRATOR-CONTROLLED and read first, with `id` as the
 * tie-break so the order is deterministic. Both public pages and the homepage
 * read it, which is what lets somebody decide that one partner comes first
 * without re-inserting rows.
 *
 * `featured` ON STORIES IS NARROWER THAN `active`. A story can be published on
 * /success-stories but left out of the homepage block, so a long tail of real
 * stories does not push the homepage section past its bound.
 *
 * No soft deletes: `active` already retires a record without destroying the
 * row, and a partner or a story is not referenced by anything financial, so
 * there is no history that a delete could orphan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();

            $table->string('name', 160);

            // The partner's own website. Optional: a partner may be listed by
            // name alone, and inventing a URL for one would be worse than
            // leaving it out.
            $table->string('url', 500)->nullable();

            // Stored relative to the public disk, under partners/. Rendered by
            // Partner::url() rather than by the templates, so a path is never
            // turned into a URL in more than one place.
            $table->string('logo_path', 500)->nullable();

            $table->text('description')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('active')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Public reads are "active ones in display order", which is exactly
            // this pair. sort_order leads, id breaks the tie.
            $table->index(['active', 'sort_order', 'id'], 'partners_active_order_index');
        });

        Schema::create('success_stories', function (Blueprint $table) {
            $table->id();

            // Who is telling it. Required: a story with no attribution is an
            // unattributed claim, which is the thing these pages must not be.
            $table->string('name', 160);

            // Role, location or similar -- the line under the name. Optional.
            $table->string('title', 160)->nullable();

            $table->text('quote');

            $table->string('image_path', 500)->nullable();

            $table->boolean('featured')->default(false);

            $table->boolean('active')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['active', 'sort_order', 'id'], 'success_stories_active_order_index');

            // The homepage block reads featured AND active in one query, so it
            // gets its own index rather than filtering the public one.
            $table->index(['featured', 'active', 'sort_order'], 'success_stories_featured_index');
        });

        // sort_order is a display position, never negative. Enforced here rather
        // than only in the form so a crafted payload cannot reorder a section
        // with a value the admin interface refuses to accept.
        //
        // MariaDB drops named CHECKs through DROP CONSTRAINT, not DROP CHECK
        // (see the Stage 20 correction), so down() uses that form.
        DB::statement('ALTER TABLE partners ADD CONSTRAINT chk_partners_sort_order CHECK (sort_order >= 0)');
        DB::statement('ALTER TABLE success_stories ADD CONSTRAINT chk_success_stories_sort_order CHECK (sort_order >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE success_stories DROP CONSTRAINT chk_success_stories_sort_order');
        DB::statement('ALTER TABLE partners DROP CONSTRAINT chk_partners_sort_order');

        Schema::dropIfExists('success_stories');
        Schema::dropIfExists('partners');
    }
};

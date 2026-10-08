<?php

declare(strict_types=1);

use App\Enums\CatalogStatus;
use App\Livewire\Admin\Content\BlogTaxonomyManager;
use App\Models\BlogCategory;
use App\Models\Tag;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * Blog categories and tags in the admin taxonomy screen.
 *
 * These are taxonomy records, not money: the rules that matter are that a slug
 * is derived rather than typed and stays unique, that a record retires by
 * archiving rather than deletion, and that each tab answers to its own
 * permission set -- holding the categories half does not hand over the tags
 * half, or the other way round.
 *
 * THE ROUTE ADMITS EITHER VIEW PERMISSION. The nav links here on the strength
 * of `blog_taxonomy.view`, the OR of the two tab permissions, so a categories-
 * only holder and a tags-only holder can both reach the screen; the screen
 * opens on the first tab they may actually see, and switching to the other is
 * refused.
 */

beforeEach(function (): void {
    seedPermissions();
});

it('opens to a holder of either taxonomy view permission', function (): void {
    $admin = userWithRole('admin');

    $this->actingAs($admin)
        ->get(route('admin.blog-taxonomy'))
        ->assertOk()
        ->assertSee('New category');
});

it('forbids an admin who holds neither taxonomy view permission', function (): void {
    // Still an admin, so the role middleware admits the request; the Gate over
    // the two view permissions is what refuses it. This isolates `can:blog_taxonomy.view`
    // rather than letting the role check wear its badge.
    $other = userWithRole('admin');

    foreach (['view', 'create', 'update', 'activate', 'archive'] as $action) {
        Role::findByName('admin')->revokePermissionTo("blog_categories.{$action}");
        Role::findByName('admin')->revokePermissionTo("blog_tags.{$action}");
    }

    $this->actingAs($other->fresh())
        ->get(route('admin.blog-taxonomy'))
        ->assertForbidden();
});

it('derives a slug from a new category name', function (): void {
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(BlogTaxonomyManager::class, ['tab' => 'categories'])
        ->call('create')
        ->set('name', 'Gaming & Tech News')
        ->set('description', 'Everything about games.')
        ->call('save')
        ->assertHasNoErrors();

    $category = BlogCategory::firstWhere('slug', 'gaming-tech-news');

    expect($category)->not->toBeNull()
        ->and($category->name)->toBe('Gaming & Tech News')
        ->and($category->description)->toBe('Everything about games.')
        ->and($category->status)->toBe(CatalogStatus::Active)
        ->and($category->created_by)->toBe($admin->id);
});

it('keeps the slug unique when the name is already taken', function (): void {
    BlogCategory::factory()->create(['name' => 'Tech', 'slug' => 'tech']);

    Livewire::actingAs(userWithRole('admin'))
        ->test(BlogTaxonomyManager::class, ['tab' => 'categories'])
        ->call('create')
        ->set('name', 'Tech')
        ->call('save')
        ->assertHasNoErrors();

    expect(BlogCategory::where('slug', 'tech-2')->exists())->toBeTrue();
});

it('creates a tag with a slug from its name', function (): void {
    Livewire::actingAs(userWithRole('admin'))
        ->test(BlogTaxonomyManager::class)
        ->call('switchTab', 'tags')
        ->call('create')
        ->set('name', 'Mobile Money')
        ->call('save')
        ->assertHasNoErrors();

    $tag = Tag::firstWhere('slug', 'mobile-money');

    expect($tag)->not->toBeNull()
        ->and($tag->status)->toBe(CatalogStatus::Active);
});

it('edits a category without touching its slug or status', function (): void {
    $category = BlogCategory::factory()->create(['name' => 'Guides', 'slug' => 'guides']);

    Livewire::actingAs(userWithRole('admin'))
        ->test(BlogTaxonomyManager::class, ['tab' => 'categories'])
        ->call('edit', $category->id)
        ->set('name', 'Field Guides')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $category->fresh();

    expect($fresh->name)->toBe('Field Guides')
        ->and($fresh->slug)->toBe('guides')
        ->and($fresh->status)->toBe(CatalogStatus::Active);
});

it('archives a category through the status action', function (): void {
    $category = BlogCategory::factory()->create();

    Livewire::actingAs(userWithRole('admin'))
        ->test(BlogTaxonomyManager::class, ['tab' => 'categories'])
        ->call('setStatus', $category->id, 'archived');

    expect($category->fresh()->status)->toBe(CatalogStatus::Archived);
});

it('reactivates an archived tag through the status action', function (): void {
    $tag = Tag::factory()->archived()->create();

    Livewire::actingAs(userWithRole('admin'))
        ->test(BlogTaxonomyManager::class, ['tab' => 'tags'])
        ->call('setStatus', $tag->id, 'active');

    expect($tag->fresh()->status)->toBe(CatalogStatus::Active);
});

it('lets a create-only holder create but not archive', function (): void {
    $staff = staffWith(['blog_categories.view', 'blog_categories.create']);

    Livewire::actingAs($staff)
        ->test(BlogTaxonomyManager::class, ['tab' => 'categories'])
        ->call('create')
        ->set('name', 'New One')
        ->call('save')
        ->assertHasNoErrors();

    $category = BlogCategory::firstWhere('name', 'New One');

    expect($category)->not->toBeNull();

    Livewire::actingAs($staff)
        ->test(BlogTaxonomyManager::class, ['tab' => 'categories'])
        ->call('setStatus', $category->id, 'archived')
        ->assertForbidden();

    expect($category->fresh()->status)->toBe(CatalogStatus::Active);
});

it('opens a tags-only holder on the tags tab', function (): void {
    $tag = Tag::factory()->create(['name' => 'Savings']);

    $tagsOnly = userWithRole('admin');
    foreach (['view', 'create', 'update', 'activate', 'archive'] as $action) {
        Role::findByName('admin')->revokePermissionTo("blog_categories.{$action}");
    }

    $this->actingAs($tagsOnly->fresh())
        ->get(route('admin.blog-taxonomy'))
        ->assertOk()
        ->assertSee('Savings')
        // The categories tab never opens without the category view permission:
        // the mount falls back to the first tab the caller may actually see.
        ->assertSee('New tag')
        ->assertDontSee('New category');
});

it('refuses a tags-only holder the categories tab', function (): void {
    $tagsOnly = staffWith(['blog_tags.view']);

    Livewire::actingAs($tagsOnly)
        ->test(BlogTaxonomyManager::class, ['tab' => 'tags'])
        ->call('switchTab', 'categories')
        ->assertForbidden();
});

it('refuses a categories-only holder the tags tab', function (): void {
    $categoriesOnly = staffWith(['blog_categories.view']);

    Livewire::actingAs($categoriesOnly)
        ->test(BlogTaxonomyManager::class, ['tab' => 'categories'])
        ->call('switchTab', 'tags')
        ->assertForbidden();
});

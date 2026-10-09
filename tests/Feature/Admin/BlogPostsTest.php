<?php

declare(strict_types=1);

use App\Livewire\Admin\Content\ContentManager;
use App\Models\BlogCategory;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
 * Blog posts in the admin content manager.
 *
 * The rules that matter are the same ones the partners and stories tabs hold
 * to, so they are asserted here again rather than assumed to transfer: ADDING
 * IS NOT PUBLISHING, a post the public can read must be both active and dated,
 * and the permission sets stay separate between tabs. A post is one record
 * with a slug instead of a sort order, and the slug is the one thing there is
 * to conflict over, so uniqueness is protected on the way in rather than left
 * to a database crash.
 */
beforeEach(function (): void {
    seedPermissions();
});

it('saves a new post unpublished and undated', function (): void {
    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('create')
        ->set('postTitle', 'Hello World')
        ->set('slug', 'hello-world')
        ->set('excerpt', 'A short line')
        ->set('body', 'The full body.')
        ->call('save')
        ->assertHasNoErrors();

    $post = Post::firstWhere('slug', 'hello-world');

    expect($post)->not->toBeNull()
        ->and($post->title)->toBe('Hello World')
        ->and($post->active)->toBeFalse()
        ->and($post->published_at)->toBeNull()
        ->and($post->created_by)->toBe($admin->id);
});

it('edits a post without republishing it', function (): void {
    $admin = userWithRole('admin');
    $post = Post::factory()->published()->create(['slug' => 'keep-it']);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('edit', $post->id)
        ->set('postTitle', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    $post->refresh();

    // Editing is typing, not deciding to show it to customers.
    expect($post->title)->toBe('Renamed')
        ->and($post->active)->toBeTrue()
        ->and($post->published_at)->not->toBeNull();
});

it('publishing a post stamps the date it went live', function (): void {
    $admin = userWithRole('admin');
    $post = Post::factory()->create(['active' => false, 'published_at' => null]);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('togglePublished', $post->id)
        ->assertHasNoErrors();

    $fresh = $post->fresh();

    expect($fresh->active)->toBeTrue()
        ->and($fresh->published_at)->not->toBeNull();
});

it('republishing an already-dated post keeps its original date', function (): void {
    $admin = userWithRole('admin');
    $original = now()->subDays(3);
    $post = Post::factory()->create(['active' => true, 'published_at' => $original]);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('togglePublished', $post->id);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('togglePublished', $post->id);

    expect($post->fresh()->published_at->timestamp)->toBe($original->timestamp);
});

it('a post becomes publicly readable only when it is both active and dated', function (): void {
    $admin = userWithRole('admin');
    $post = Post::factory()->create(['title' => 'Readable When Live', 'active' => false, 'published_at' => null]);

    $this->get(route('blog.show', $post))->assertNotFound();

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('togglePublished', $post->id);

    $this->get(route('blog.show', $post->fresh()))->assertOk()->assertSee('Readable When Live');
});

it('refuses a slug another post already uses, on create', function (): void {
    Post::factory()->create(['slug' => 'taken', 'active' => false]);

    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('create')
        ->set('postTitle', 'Duplicate')
        ->set('slug', 'taken')
        ->set('body', 'Body.')
        ->call('save')
        ->assertHasErrors('slug');

    // No second row appeared and no published record was produced.
    expect(Post::where('slug', 'taken')->count())->toBe(1);
});

it('refuses a slug another post already uses, on edit', function (): void {
    [$a, $b] = [
        Post::factory()->create(['slug' => 'alpha', 'active' => false]),
        Post::factory()->create(['slug' => 'beta', 'active' => false]),
    ];

    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('edit', $a->id)
        ->set('slug', 'beta')
        ->call('save')
        ->assertHasErrors('slug');

    expect($a->fresh()->slug)->toBe('alpha');
});

it('stores a post image under the posts directory', function (): void {
    Storage::fake('public');

    $admin = userWithRole('admin');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('create')
        ->set('postTitle', 'Pictured')
        ->set('slug', 'pictured')
        ->set('body', 'Body.')
        ->set('image', TemporaryUploadedFile::fake()->image('cover.jpg', 400, 300))
        ->call('save')
        ->assertHasNoErrors();

    $post = Post::firstWhere('slug', 'pictured');

    expect($post->image_path)->toStartWith('posts/');

    Storage::disk('public')->assertExists($post->image_path);
});

it('does not let a partner permission reach a blog post', function (): void {
    $admin = userWithRole('admin');
    foreach (['create', 'update', 'activate'] as $action) {
        Role::findByName('admin')->revokePermissionTo("posts.{$action}");
    }

    Livewire::actingAs($admin->fresh())
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->assertOk()
        ->call('create')
        ->assertForbidden();

    expect(Post::count())->toBe(0);
});

it('does not let editing permission alone publish a post', function (): void {
    $admin = userWithRole('admin');
    Role::findByName('admin')->revokePermissionTo('posts.activate');

    $post = Post::factory()->create(['active' => false]);

    Livewire::actingAs($admin->fresh())
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('togglePublished', $post->id)
        ->assertForbidden();

    expect($post->fresh()->active)->toBeFalse();
});

it('opens the posts tab to a role holder who only holds the post permissions', function (): void {
    $admin = userWithRole('admin');
    foreach (['view', 'create', 'update', 'activate'] as $action) {
        Role::findByName('admin')->revokePermissionTo("partners.{$action}");
        Role::findByName('admin')->revokePermissionTo("success_stories.{$action}");
    }

    $this->actingAs($admin->fresh())
        ->get(route('admin.content'))
        ->assertOk()
        // The posts columns, and neither of the other tabs'.
        ->assertSee('Slug')
        ->assertDontSee('Website')
        ->assertDontSee('What they said');
});

/*
 * The post's taxonomy and search-engine metadata, saved in one pass.
 *
 * A post belongs to at most one existing category and carries existing tags
 * picked rather than typed, and the meta fields shape what the public page
 * says about itself -- so the form has to persist all of them together with
 * the body, and refuse a reference to a row that does not exist.
 */

it('persists the category, tags and search metadata with the post', function (): void {
    $category = BlogCategory::factory()->create(['name' => 'Guides']);
    $tagA = Tag::factory()->create(['name' => 'Tips']);
    $tagB = Tag::factory()->create(['name' => 'Ghana']);

    Livewire::actingAs(userWithRole('admin'))
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('create')
        ->set('postTitle', 'SEO Ready')
        ->set('slug', 'seo-ready')
        ->set('body', '<p>Body.</p>')
        ->set('categoryId', $category->id)
        ->set('tags', [$tagA->id, $tagB->id])
        ->set('metaTitle', 'SEO Ready — Revisited')
        ->set('metaDescription', 'A tidy description.')
        ->set('primaryKeyword', 'credit')
        ->set('secondaryKeywords', 'auction, ghana, credit')
        ->call('save')
        ->assertHasNoErrors();

    $post = Post::firstWhere('slug', 'seo-ready');

    expect($post)->not->toBeNull()
        ->and($post->category_id)->toBe($category->id)
        ->and($post->tags()->pluck('tags.id')->sort()->values()->all())->toBe([$tagA->id, $tagB->id])
        ->and($post->meta_title)->toBe('SEO Ready — Revisited')
        ->and($post->meta_description)->toBe('A tidy description.')
        ->and($post->primary_keyword)->toBe('credit')
        ->and($post->secondary_keywords)->toBe(['auction', 'ghana', 'credit']);
});

it('replaces the tag set when the post is saved again', function (): void {
    $post = Post::factory()->published()->create(['slug' => 'retag']);
    $old = Tag::factory()->create(['name' => 'Old']);
    $new = Tag::factory()->create(['name' => 'New']);

    $post->tags()->attach($old);

    Livewire::actingAs(userWithRole('admin'))
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('edit', $post->id)
        ->set('tags', [$new->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->tags()->pluck('tags.id')->all())->toBe([$new->id]);
});

it('refuses a tag that does not exist', function (): void {
    Livewire::actingAs(userWithRole('admin'))
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('create')
        ->set('postTitle', 'Bad Tag')
        ->set('slug', 'bad-tag')
        ->set('body', '<p>Body.</p>')
        ->set('tags', [999_999])
        ->call('save')
        ->assertHasErrors('tags.0');

    expect(Post::where('slug', 'bad-tag')->doesntExist())->toBeTrue();
});

it('refuses a category that does not exist', function (): void {
    Livewire::actingAs(userWithRole('admin'))
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('create')
        ->set('postTitle', 'Bad Category')
        ->set('slug', 'bad-category')
        ->set('body', '<p>Body.</p>')
        ->set('categoryId', 999_999)
        ->call('save')
        ->assertHasErrors('categoryId');
});

/*
 * The body's one path into the database.
 *
 * A post body is rich text rendered with `{!! !!}`, so it stores whatever the
 * whitelist allowed and nothing else: dangerous markup never survives, and the
 * allowed formatting does. The form cleans on the way in, not at render time,
 * so a hostile paste is defused once, at the write.
 */

it('stores the body through the whitelist, not as typed', function (): void {
    Livewire::actingAs(userWithRole('admin'))
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('create')
        ->set('postTitle', 'Clean Me')
        ->set('slug', 'clean-me')
        ->set('body', '<p>Fine <strong>text</strong>.</p><script>alert(1)</script><p onclick="h()">Bad</p>')
        ->call('save')
        ->assertHasNoErrors();

    $post = Post::firstWhere('slug', 'clean-me');

    expect($post->body)->not->toContain('script')
        ->and($post->body)->not->toContain('onclick')
        ->and($post->body)->toStartWith('<p>Fine <strong>text</strong>.</p>')
        ->and($post->body)->toEndWith('</p>');
});

/*
 * A post delete is a permanent removal: the row, its image file, and every
 * public surface that read it -- the site and therefore the sitemap -- stop
 * showing it at once. A published post deleted this way never re-appears,
 * which is what "permanent" means.
 */

it('deletes a post, its image file and its row together', function (): void {
    Storage::fake('public');

    $admin = userWithRole('admin');
    $post = Post::factory()->published()->create(['slug' => 'doomed']);
    $post->image_path = 'posts/doomed.jpg';
    $post->save();
    Storage::disk('public')->put('posts/doomed.jpg', 'x');

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('deleteRecord', $post->id)
        ->assertHasNoErrors();

    expect(Post::where('slug', 'doomed')->doesntExist())->toBeTrue()
        ->and(Storage::disk('public')->missing('posts/doomed.jpg'))->toBeTrue();

    // Gone from the public blog the moment the row is gone.
    $this->get(route('blog.show', ['post' => 'doomed']))->assertNotFound();
});

it('records a post deletion in the content activity log', function (): void {
    $admin = userWithRole('admin');
    $post = Post::factory()->published()->create(['slug' => 'logged-deletion']);

    Livewire::actingAs($admin)
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('deleteRecord', $post->id);

    expect(Activity::query()
        ->where('log_name', 'content')
        ->where('description', 'deleted')
        ->where('subject_type', Post::class)
        ->where('subject_id', $post->id)
        ->exists()
    )->toBeTrue();
});

it('refuses to delete a post without the posts.delete permission', function (): void {
    $admin = userWithRole('admin');
    Role::findByName('admin')->revokePermissionTo('posts.delete');
    $post = Post::factory()->create(['slug' => 'safe']);

    Livewire::actingAs($admin->fresh())
        ->test(ContentManager::class, ['tab' => 'posts'])
        ->call('deleteRecord', $post->id)
        ->assertForbidden();

    expect(Post::whereKey($post->id)->exists())->toBeTrue()
        ->and(Post::where('slug', 'safe')->exists())->toBeTrue();
});

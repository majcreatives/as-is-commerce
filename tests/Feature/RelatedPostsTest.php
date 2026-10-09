<?php

declare(strict_types=1);

use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
use App\Models\BlogCategory;
use App\Models\Post;
use App\Models\Tag;

it('suggests posts from the same category first, newest first', function (): void {
    $category = BlogCategory::factory()->create();

    $self = Post::factory()->published()->create([
        'title' => 'The Opened Post',
        'category_id' => $category->id,
        'published_at' => now()->subDays(5),
    ]);
    $sameOlder = Post::factory()->published()->create([
        'title' => 'Same Category Older',
        'category_id' => $category->id,
        'published_at' => now()->subDays(3),
    ]);
    $sameNewer = Post::factory()->published()->create([
        'title' => 'Same Category Newer',
        'category_id' => $category->id,
        'published_at' => now()->subDays(1),
    ]);
    $other = Post::factory()->published()->create([
        'title' => 'Other Category Post',
        'category_id' => BlogCategory::factory()->create()->id,
        'published_at' => now()->subDays(1),
    ]);

    $related = app(ContentDiscoveryQuery::class)->relatedPosts($self);

    expect($related->pluck('id')->all())
        ->toBe([$sameNewer->id, $sameOlder->id])
        ->and($related->pluck('title')->all())
        ->not->toContain($other->title);

    // And the page itself carries the strip, minus the post it is on.
    $this->get(route('blog.show', $self))
        ->assertOk()
        ->assertSee('Related articles')
        ->assertSee('Same Category Newer')
        ->assertSee('Same Category Older')
        ->assertDontSee('Other Category Post');
});

it('fills a thin category from posts sharing a tag', function (): void {
    $category = BlogCategory::factory()->create();
    $otherCategory = BlogCategory::factory()->create();
    $tag = Tag::factory()->create();

    $self = Post::factory()->published()->create([
        'title' => 'Tagged Post',
        'category_id' => $category->id,
        'published_at' => now()->subDays(4),
    ]);
    $self->tags()->attach($tag);

    $sameCategory = Post::factory()->published()->create([
        'title' => 'Only Same Category Neighbour',
        'category_id' => $category->id,
        'published_at' => now()->subDays(3),
    ]);
    $sharedTag = Post::factory()->published()->create([
        'title' => 'Shared Tag Neighbour',
        'category_id' => $otherCategory->id,
        'published_at' => now()->subDays(2),
    ]);
    $sharedTag->tags()->attach($tag);
    $unrelated = Post::factory()->published()->create([
        'title' => 'Unrelated Neighbour',
        'category_id' => $otherCategory->id,
        'published_at' => now()->subDay(),
    ]);

    expect(app(ContentDiscoveryQuery::class)->relatedPosts($self)->pluck('id')->all())
        ->toBe([$sameCategory->id, $sharedTag->id])
        ->and(app(ContentDiscoveryQuery::class)->relatedPosts($self)->pluck('title')->all())
        ->not->toContain($unrelated->title);
});

it('bounds related posts to the strip size', function (): void {
    $category = BlogCategory::factory()->create();

    $self = Post::factory()->published()->create([
        'category_id' => $category->id,
        'published_at' => now()->subDays(8),
    ]);

    Post::factory()->published()->count(6)->create([
        'category_id' => $category->id,
        'published_at' => now()->subDays(1),
    ]);

    expect(app(ContentDiscoveryQuery::class)->relatedPosts($self))->toHaveCount(4);
});

it('never suggests a draft or an undated post', function (): void {
    $category = BlogCategory::factory()->create();

    $self = Post::factory()->published()->create([
        'category_id' => $category->id,
        'published_at' => now()->subDays(2),
    ]);

    Post::factory()->create([
        'title' => 'Hidden Draft',
        'category_id' => $category->id,
        'active' => false,
    ]);
    Post::factory()->undated()->create([
        'title' => 'Hidden Undated',
        'category_id' => $category->id,
    ]);

    expect(app(ContentDiscoveryQuery::class)->relatedPosts($self)->pluck('title')->all())->toBe([]);
});

it('hides the strip when nothing is related', function (): void {
    $self = Post::factory()->published()->create([
        'title' => 'Lonely Post',
        'category_id' => null,
        'published_at' => now(),
    ]);

    expect(app(ContentDiscoveryQuery::class)->relatedPosts($self))->toBeEmpty();

    $this->get(route('blog.show', $self))
        ->assertOk()
        ->assertSee('Lonely Post')
        ->assertDontSee('Related articles');
});

<?php

declare(strict_types=1);

use App\Models\BlogCategory;
use App\Models\Post;
use App\Models\Tag;

/*
 * The public faces of the blog taxonomy, and the search-engine output of a post.
 *
 * A category or tag exists as a page only while it is active and only shows
 * posts that are both active and dated: everything else is either invisible
 * (an unpublished draft) or a 404 (an archived taxonomy), because a page a
 * crawler should not find must not be made findable by accident.
 *
 * THE POST'S META OUTPUT IS TRUTHFUL OR ABSENT. A meta title and description
 * fall back to the post's own title and excerpt rather than being invented, a
 * keywords tag is emitted only when the post named keywords, and the JSON-LD
 * describes what the post declared -- its section is the category a reader can
 * see, its keywords are the ones an editor wrote.
 */

it('lists only published posts on a category page', function (): void {
    $category = BlogCategory::factory()->create(['name' => 'Guides']);

    Post::factory()->published()->create(['title' => 'Visible Post', 'category_id' => $category->id]);
    Post::factory()->create(['title' => 'Hidden Draft', 'category_id' => $category->id, 'active' => false]);

    $this->get(route('blog.category', $category))
        ->assertOk()
        ->assertSee('Visible Post')
        ->assertDontSee('Hidden Draft');
});

it('404s a category page whose category is not active', function (string $state): void {
    $category = BlogCategory::factory()->{$state}()->create();

    Post::factory()->published()->create(['category_id' => $category->id]);

    $this->get(route('blog.category', $category))->assertNotFound();
})->with(['inactive', 'archived']);

it('lists only published posts on a tag page', function (): void {
    $tag = Tag::factory()->create(['name' => 'Tips']);

    Post::factory()->published()->create(['title' => 'Tagged and Live'])->tags()->attach($tag);
    Post::factory()->create(['title' => 'Tagged But Hidden', 'active' => false])->tags()->attach($tag);

    $this->get(route('blog.tag', $tag))
        ->assertOk()
        ->assertSee('Tagged and Live')
        ->assertDontSee('Tagged But Hidden');
});

it('404s a tag page whose tag is not active', function (string $state): void {
    $tag = Tag::factory()->{$state}()->create();

    Post::factory()->published()->create(['title' => 'Behind The Tag'])->tags()->attach($tag);

    $this->get(route('blog.tag', $tag))->assertNotFound();
})->with(['inactive', 'archived']);

it('renders a post with its category, tags and stored body', function (): void {
    $category = BlogCategory::factory()->create(['name' => 'Guides']);
    $tagA = Tag::factory()->create(['name' => 'Tips']);
    $tagB = Tag::factory()->create(['name' => 'Ghana']);

    $post = Post::factory()->published()->create([
        'category_id' => $category->id,
        'body' => '<p>Hello <strong>world</strong>.</p>',
    ]);
    $post->tags()->attach([$tagA->id, $tagB->id]);

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee(route('blog.category', $category))
        ->assertSee($tagA->name)
        ->assertSee($tagB->name)
        // The body is stored as whitelisted HTML and rendered raw.
        ->assertSee('<strong>world</strong>', false);
});

it('names a post for search engines from its meta fields when supplied', function (): void {
    $post = Post::factory()->published()->withSeo()->create();

    $brand = config('app.name');

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('<title>'.$post->meta_title.' — '.$brand.'</title>', false)
        ->assertSee('<meta name="description" content="'.$post->meta_description.'">', false);
});

it('falls back to the post title and body for search-engine output', function (): void {
    $post = Post::factory()->published()->create([
        'meta_title' => null,
        'meta_description' => null,
        'excerpt' => null,
        'body' => 'A very specific line of body text.',
    ]);

    $brand = config('app.name');
    $html = $this->get(route('blog.show', $post))->assertOk()->getContent();

    expect($html)->toContain('<title>'.$post->title.' — '.$brand.'</title>')
        ->and($html)->toContain('<meta name="description" content="A very specific line of body text.">');
});

it('emits a keywords meta tag only when the post names keywords', function (): void {
    $named = Post::factory()->published()->create([
        'primary_keyword' => 'credit',
        'secondary_keywords' => ['auction', 'ghana'],
    ]);

    $this->get(route('blog.show', $named))
        ->assertOk()
        ->assertSee('<meta name="keywords" content="credit, auction, ghana">', false);

    $unnamed = Post::factory()->published()->create();

    $this->get(route('blog.show', $unnamed))
        ->assertOk()
        ->assertDontSee('<meta name="keywords"', false);
});

it('emits truthful structured data for a post', function (): void {
    $category = BlogCategory::factory()->create(['name' => 'Finance']);

    $post = Post::factory()->published()->withSeo()->create(['category_id' => $category->id]);

    $html = $this->get(route('blog.show', $post))->assertOk()->getContent();

    expect($html)->toContain('"@type":"BlogPosting"')
        ->and($html)->toContain('"mainEntityOfPage"')
        ->and($html)->toContain('"inLanguage"')
        // The section is the category a reader can see, and the keywords are
        // the ones the post itself declared.
        ->and($html)->toContain('"articleSection":"Finance"')
        ->and($html)->toContain('"keywords":"'.$post->seoKeywords()[0]);

    $uncategorised = Post::factory()->published()->create(['category_id' => null]);

    $html = $this->get(route('blog.show', $uncategorised))->assertOk()->getContent();

    expect($html)->not->toContain('"articleSection"')
        ->and($html)->not->toContain('"about"');
});

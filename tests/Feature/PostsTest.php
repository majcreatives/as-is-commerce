<?php

declare(strict_types=1);

use App\Models\Post;

it('shows published posts on the blog index', function (): void {
    Post::factory()->published()->create(['title' => 'First Post']);
    Post::factory()->create(['title' => 'Draft Post', 'active' => false]);

    $this->get(route('blog.index'))
        ->assertOk()
        ->assertSee('First Post')
        ->assertDontSee('Draft Post');
});

it('shows a single published post', function (): void {
    $post = Post::factory()->published()->create([
        'title' => 'Hello World',
        'body' => 'This is the body',
    ]);

    $this->get(route('blog.show', $post))
        ->assertOk()
        ->assertSee('Hello World')
        ->assertSee('This is the body');
});

it('does not show unpublished posts', function (): void {
    $post = Post::factory()->create(['active' => false]);

    $this->get(route('blog.show', $post))->assertNotFound();
});

it('shows the four newest published posts on the homepage', function (): void {
    Post::factory()->published()->create(['title' => 'Oldest', 'published_at' => now()->subDays(10)]);
    Post::factory()->published()->create(['title' => 'Older', 'published_at' => now()->subDays(8)]);
    Post::factory()->published()->create(['title' => 'Middle', 'published_at' => now()->subDays(6)]);
    Post::factory()->published()->create(['title' => 'Newer', 'published_at' => now()->subDays(4)]);
    Post::factory()->published()->create(['title' => 'Newest', 'published_at' => now()->subDays(2)]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('From the blog')
        ->assertSee('All posts')
        // Newest first, and the fifth post has no homepage slot.
        ->assertSee('Newest')
        ->assertSee('Newer')
        ->assertSee('Middle')
        ->assertSee('Older')
        ->assertDontSee('Oldest');
});

it('hides the blog section on the homepage when nothing is published', function (): void {
    Post::factory()->create(['title' => 'Draft', 'active' => false]);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('From the blog')
        ->assertDontSee('All posts');
});

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

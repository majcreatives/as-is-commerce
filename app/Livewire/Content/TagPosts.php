<?php

declare(strict_types=1);

namespace App\Livewire\Content;

use App\Enums\CatalogStatus;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public page behind /blog/tag/{slug}.
 *
 * The same shape as {@see CategoryPosts}: an active tag is a page, an archived
 * one 404s, and only published posts appear.
 */
#[Layout('components.layouts.app')]
class TagPosts extends Component
{
    use WithPagination;

    public Tag $tag;

    public function mount(): void
    {
        abort_if($this->tag->status !== CatalogStatus::Active, 404);
    }

    public function render(): View
    {
        $posts = $this->tag->posts()
            ->published()
            ->with(['category', 'tags'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12);

        return view('livewire.content.tag-posts', [
            'posts' => $posts,
        ])
            ->title("{$this->tag->name} — Blog")
            ->layoutData([
                'description' => "Posts tagged {$this->tag->name} on the As-Is-Commerce blog.",
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Content;

use App\Enums\CatalogStatus;
use App\Models\BlogCategory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public page behind /blog/category/{slug}.
 *
 * Only an active category is a page: an archived category still exists to keep
 * its posts' history, but it is no longer something the public should find, so
 * it 404s rather than rendering an empty hall.
 */
#[Layout('components.layouts.app')]
class CategoryPosts extends Component
{
    use WithPagination;

    public BlogCategory $category;

    public function mount(): void
    {
        abort_if($this->category->status !== CatalogStatus::Active, 404);
    }

    public function render(): View
    {
        $posts = $this->category->posts()
            ->published()
            ->with(['category', 'tags'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12);

        return view('livewire.content.category-posts', [
            'posts' => $posts,
        ])
            ->title("{$this->category->name} — Blog")
            ->layoutData([
                'description' => $this->category->description
                    ?? "Posts published under {$this->category->name} on the As-Is-Commerce blog.",
            ]);
    }
}

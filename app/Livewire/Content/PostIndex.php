<?php

declare(strict_types=1);

namespace App\Livewire\Content;

use App\Models\Post;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Blog')]
class PostIndex extends Component
{
    use WithPagination;

    public function render(): View
    {
        $posts = Post::query()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12);

        return view('livewire.content.post-index', [
            'posts' => $posts,
        ])->layoutData([
            'description' => 'Read the latest from As-Is-Commerce — news, updates and insights from the marketplace.',
        ]);
    }
}

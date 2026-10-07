<?php

declare(strict_types=1);

namespace App\Livewire\Content;

use App\Models\Post;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PostShow extends Component
{
    public Post $post;

    public function mount(): void
    {
        if (! $this->post->active || $this->post->published_at === null) {
            abort(404);
        }
    }

    public function render(): View
    {
        $publishedAt = $this->post->published_at;
        $description = $this->post->excerpt ?? str()->limit(strip_tags($this->post->body), 160);

        return view('livewire.content.post-show', [
            'post' => $this->post,
        ])
            ->title($this->post->title)
            ->layoutData([
                'description' => $description,
                'ogImage' => $this->post->imageUrl(),
                'structuredData' => $this->buildStructuredData($description, $publishedAt),
                'ogType' => 'article',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStructuredData(string $description, ?Carbon $publishedAt): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $this->post->title,
            'datePublished' => $publishedAt?->toIso8601String(),
            'dateModified' => $this->post->updated_at->toIso8601String(),
            'image' => $this->post->imageUrl(),
            'description' => $description,
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
            ],
        ]);
    }
}

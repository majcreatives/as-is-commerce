<?php

declare(strict_types=1);

namespace App\Livewire\Content;

use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
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

        $this->post->loadMissing(['category', 'tags']);
    }

    public function render(ContentDiscoveryQuery $content): View
    {
        // A page that names itself for search engines: the explicitly-written
        // meta fields win, the editorial fields fill in, and the body is the
        // last resort -- so a short post never advertises a blank line.
        $title = $this->post->meta_title ?: $this->post->title;
        $description = $this->post->meta_description
            ?? $this->post->excerpt
            ?? str()->limit(strip_tags($this->post->body), 160);

        $keywords = $this->post->seoKeywords();
        $publishedAt = $this->post->published_at;

        $layoutData = [
            'description' => $description,
            'ogImage' => $this->post->imageUrl(),
            'structuredData' => $this->buildStructuredData($description, $publishedAt),
            'ogType' => 'article',
        ];

        // A meta keywords tag is only ever emitted when the post actually names
        // keywords; the layout skips a null, so nothing is invented here.
        if ($keywords !== null) {
            $layoutData['keywords'] = $keywords;
        }

        return view('livewire.content.post-show', [
            'post' => $this->post,
            // The reader is already inside the topic, so the trail of what
            // they read next, newest first, is shown where it is useful.
            'related' => $content->relatedPosts($this->post),
        ])
            ->title($title)
            ->layoutData($layoutData);
    }

    /**
     * Truthful structured data only. `keywords` and `about` describe what the
     * post itself declared; `articleSection` is the category a reader can see;
     * nothing is derived that the post did not say.
     *
     * @return array<string, mixed>
     */
    private function buildStructuredData(string $description, ?Carbon $publishedAt): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $this->post->title,
            'datePublished' => $publishedAt?->toIso8601String(),
            'dateModified' => $this->post->updated_at->toIso8601String(),
            'image' => $this->post->imageUrl(),
            'description' => $description,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => url()->current(),
            ],
            'inLanguage' => app()->getLocale(),
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
            ],
        ];

        $keywords = $this->post->seoKeywords();
        if ($keywords !== null) {
            $data['keywords'] = implode(', ', $keywords);
        }

        if ($this->post->category) {
            $data['articleSection'] = $this->post->category->name;
            $data['about'] = [
                '@type' => 'Thing',
                'name' => $this->post->category->name,
            ];
        }

        return $data;
    }
}

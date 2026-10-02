<?php

declare(strict_types=1);

namespace App\Livewire\Content;

use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public success stories page.
 *
 * PUBLISHED STORIES ONLY. `featured` is deliberately not part of what this page
 * reads: this is the full list, and being featured only earns a slot on the
 * homepage.
 *
 * WHAT THIS PAGE MAY SAY. A success story is a customer saying something about
 * their own experience. It is attributed to a name, and it is never linked to
 * an order, a payment, an auction or a credit balance -- none of that is on
 * this page and none of it is readable from it. If nothing has been published,
 * the page says so.
 */
#[Layout('components.layouts.app')]
#[Title('Success stories')]
class SuccessStoryIndex extends Component
{
    use WithPagination;

    public function render(ContentDiscoveryQuery $content): View
    {
        return view('livewire.content.success-story-index', [
            'stories' => $content->stories(),
        ])->layoutData([
            'description' => 'What customers have said about shopping and winning with us.',
        ]);
    }
}

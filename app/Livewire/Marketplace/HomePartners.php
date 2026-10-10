<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The partners strip on the front page.
 *
 * LAZY BY DESIGN, and for the same reason {@see HomeSuccessStories}: it is
 * below the fold, it disappears entirely when no partner is published, and it
 * is supplemental content -- the shop, the auctions, and the blog stay in the
 * eager HTML. Making it its own lazy component keeps the query cost off the
 * homepage until a reader reaches the block.
 *
 * The `published` scope still guards the query, so a draft can never appear
 * here, and the block renders nothing rather than a heading over empty space.
 */
#[Lazy]
class HomePartners extends Component
{
    public function render(ContentDiscoveryQuery $content): View
    {
        return view('livewire.marketplace.home-partners', [
            'partners' => $content->homepagePartners(),
        ]);
    }
}

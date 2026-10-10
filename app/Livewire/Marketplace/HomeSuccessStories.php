<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
use Illuminate\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The success stories block on the front page.
 *
 * LAZY BY DESIGN. This sits below the fold on the only page that shows it, and
 * the whole section disappears when no story qualifies anyway. It is its own
 * component so the homepage can hand it its own query cost only when a visitor
 * actually reaches it, instead of paying for the block on every visit whether
 * or not it is ever scrolled to.
 *
 * The trade is deliberate and worth stating: Googlebot does not run JavaScript,
 * so the stories this block renders after a scroll are not part of the initial
 * HTML a crawler reads. The section is supplemental content -- the shop, the
 * auctions, and the blog remain in the eager HTML -- and a marketplace shape
 * that already hides a whole section when it has nothing to say is comfortable
 * with the same section being absent until a reader reaches it.
 *
 * No requirement is loosened by the move: the query still uses the `published`
 * and `featured` scopes, so a draft never leaks onto the homepage, and the
 * section still renders nothing instead of a heading over empty space.
 */
#[Lazy]
class HomeSuccessStories extends Component
{
    public function render(ContentDiscoveryQuery $content): View
    {
        return view('livewire.marketplace.home-success-stories', [
            'stories' => $content->homepageStories(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Models\Product;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The front page.
 *
 * A SHOP FIRST. The store is the identity and the primary commerce; a
 * gamified auction channel is layered over it for the products that carry
 * one. A homepage that led with the auction channel would misrepresent what
 * the business is, so the shop leads and any live auction is surfaced on the
 * product's own card rather than as a banner above the store.
 *
 * NOTHING IS PROMISED. No claim about savings, no "win it for pennies", no
 * guarantee of anything. The copy says what the mechanism is and lets somebody
 * decide -- which is also the only kind of claim the backend could support.
 *
 * REAL CONTENT OR NONE. What appears here is read from the catalog and from the
 * company content an administrator has published. When there is nothing live,
 * the page says so rather than showing examples: a fabricated product on a
 * homepage is the same lie as a fabricated one anywhere else.
 */
#[Layout('components.layouts.app')]
class Home extends Component
{
    public function render(ProductDiscoveryQuery $products, ContentDiscoveryQuery $content): View
    {
        $featured = $products->featured(8);
        $trending = $products->trending(4);

        // One batched availability pass for the whole page, so each product
        // appears once with the auction that is actually relevant to it.
        $availability = $products->availabilityFor($featured->concat($trending));

        // What the hero collage is made of: the real products this page has
        // already read, never an example or a stock image, for the reason in
        // the class note above. Only products that actually have a picture can
        // appear -- a card with nothing in it is worse than a missing card.
        // Free: featured() and trending() both eager-load `images`, so reading
        // the URL costs no query. De-duplicated because a recently published
        // product can also be trending, and it must not appear twice.
        // Six, because the collage is three columns of two. A seventh has
        // nowhere to go and would only push the visible cards further down.
        $gallery = $featured->concat($trending)
            ->filter(fn (Product $product): bool => $product->image() !== null)
            ->unique(fn (Product $product): int => $product->getKey())
            ->shuffle()
            ->take(6)
            ->values();

        return view('livewire.marketplace.home', [
            'featured' => $featured,
            'trending' => $trending,
            'availability' => $availability,
            'gallery' => $gallery,
            // Bounded by the query, and all are empty until an administrator
            // has published something. The view hides a section it is given
            // nothing for, rather than rendering an empty frame.
            'partners' => $content->homepagePartners(),
            'stories' => $content->homepageStories(),
            'posts' => $content->homepagePosts(),
        ])->title(config('app.name').' — the shop, and the auction');
    }
}

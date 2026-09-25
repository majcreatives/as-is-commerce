<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
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
 * REAL CONTENT OR NONE. What appears here is read from the catalog. When there
 * is nothing live, the page says so rather than showing examples: a fabricated
 * product on a homepage is the same lie as a fabricated one anywhere else.
 */
#[Layout('components.layouts.app')]
class Home extends Component
{
    public function render(ProductDiscoveryQuery $products): View
    {
        $featured = $products->featured(8);
        $trending = $products->trending(8);

        // One batched availability pass for the whole page, so each product
        // appears once with the auction that is actually relevant to it.
        $availability = $products->availabilityFor($featured->concat($trending));

        return view('livewire.marketplace.home', [
            'featured' => $featured,
            'trending' => $trending,
            'availability' => $availability,
        ])->title(config('app.name').' — the shop, and the auction');
    }
}

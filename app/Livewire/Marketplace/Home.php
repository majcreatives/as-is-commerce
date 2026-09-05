<?php

declare(strict_types=1);

namespace App\Livewire\Marketplace;

use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Marketplace\Queries\AuctionDiscoveryQuery;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Models\Auction;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The front page.
 *
 * TWO PATHS, SIDE BY SIDE. The whole proposition of this platform is that a
 * customer may buy a thing outright in cedis, or compete for it with credits.
 * A homepage that led with only one would misrepresent what the business is,
 * so both are stated at the top and both have a way in.
 *
 * NOTHING IS PROMISED. No claim about savings, no "win it for pennies", no
 * guarantee of anything. The copy says what the mechanism is and lets somebody
 * decide -- which is also the only kind of claim the backend could support.
 *
 * REAL CONTENT OR NONE. What appears here is read from the catalog and the
 * auction engine. When there is nothing live, the page says so rather than
 * showing examples: a fabricated auction on a homepage is the same lie as a
 * fabricated one anywhere else.
 */
#[Layout('components.layouts.app')]
class Home extends Component
{
    public function render(
        ProductDiscoveryQuery $products,
        AuctionDiscoveryQuery $auctions,
        AuctionClock $clock,
    ): View {
        $live = $auctions->endingSoonest(4);
        $featured = $products->featured(8);

        return view('livewire.marketplace.home', [
            'liveAuctions' => $live,
            'remaining' => $live
                ->mapWithKeys(fn (Auction $a): array => [$a->id => $clock->secondsRemaining($a)])
                ->all(),
            'openAuctionCount' => $auctions->openCount(),
            'featured' => $featured,
            'availability' => $products->availabilityFor($featured),
        ])->title(config('app.name').' — buy it outright, or bid with credits');
    }
}

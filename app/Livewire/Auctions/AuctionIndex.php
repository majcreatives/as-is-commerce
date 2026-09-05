<?php

declare(strict_types=1);

namespace App\Livewire\Auctions;

use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Marketplace\Queries\AuctionDiscoveryQuery;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Enums\ProductCondition;
use App\Models\Auction;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every auction a customer can take part in.
 *
 * A thin component over {@see AuctionDiscoveryQuery}. What counts as an
 * opportunity -- live, closing or scheduled -- is decided there, so a
 * cancelled, forfeited, settled or unsold auction cannot reach a list of
 * things to bid on through a filter or a crafted query string.
 *
 * "ENDING SOONEST" IS ORDERED BY THE AUCTION'S OWN TIMESTAMP, never by a
 * countdown a browser has been running. The countdown on each card is a number
 * the server worked out at render time and handed over for display; it decides
 * nothing, and an auction ends when `ends_at` says so whether or not anybody
 * is watching.
 */
#[Layout('components.layouts.app')]
#[Title('Auctions')]
class AuctionIndex extends Component
{
    use WithPagination;

    /** open | ended | all */
    #[Url]
    public string $filter = 'open';

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $condition = '';

    #[Url]
    public string $sort = 'ending_soon';

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'condition');
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->category !== '' || $this->condition !== '';
    }

    public function render(
        AuctionDiscoveryQuery $auctions,
        ProductDiscoveryQuery $products,
        AuctionClock $clock,
    ): View {
        $page = $auctions->paginate([
            'search' => $this->search,
            'category' => $this->category,
            'condition' => $this->condition,
            'filter' => $this->filter,
            'sort' => $this->sort,
        ]);

        return view('livewire.auctions.auction-index', [
            'auctions' => $page,
            // Worked out here, once per card, from the server's own clock.
            'remaining' => collect($page->items())
                ->mapWithKeys(fn (Auction $a): array => [$a->id => $clock->secondsRemaining($a)])
                ->all(),
            'categories' => $products->categories(),
            'conditions' => ProductCondition::cases(),
            'sorts' => AuctionDiscoveryQuery::SORTS,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Auctions;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public auction listing.
 *
 * Drafts never appear: an unpublished auction is not a promise to anyone. The
 * query starts from the `publiclyVisible` scope rather than a status check
 * written out here, so a listing cannot leak one through a filter someone
 * forgot.
 *
 * Closed auctions stay listed. A bidder who spent credits is entitled to see
 * how the auction ended.
 *
 * Nothing here is fabricated. With no auctions created, this page shows an
 * empty state rather than examples.
 */
#[Layout('components.layouts.app')]
#[Title('Auctions')]
class AuctionIndex extends Component
{
    use WithPagination;

    /**
     * open | ended | all
     */
    #[Url]
    public string $filter = 'open';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.auctions.auction-index', [
            'auctions' => $this->auctions(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Auction>
     */
    private function auctions(): LengthAwarePaginator
    {
        $query = Auction::query()
            ->publiclyVisible()
            ->with(['product.brand', 'highestBid']);

        $query = match ($this->filter) {
            'ended' => $query->whereNotIn('status', [
                AuctionStatus::Live, AuctionStatus::Closing, AuctionStatus::Scheduled,
            ]),
            'all' => $query,
            // Scheduled auctions are shown alongside open ones: they are the
            // ones worth watching for.
            default => $query->whereIn('status', [
                AuctionStatus::Live, AuctionStatus::Closing, AuctionStatus::Scheduled,
            ]),
        };

        // Ending soonest first for anything still running, so the listing
        // answers the question a bidder actually has.
        return $query
            ->orderByRaw('ends_at IS NULL, ends_at ASC')
            ->orderByDesc('id')
            ->paginate(12);
    }
}

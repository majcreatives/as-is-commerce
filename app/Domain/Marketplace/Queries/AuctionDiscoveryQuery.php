<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Queries;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finding auctions, for the auction hall.
 *
 * READ ONLY, and authoritative about nothing. It assembles listings; the
 * auction engine decides what an auction is, when it closes and who wins.
 *
 * WHAT COUNTS AS AN OPPORTUNITY. Live, closing and scheduled -- the three
 * states a customer can still act on or wait for. Cancelled, forfeited,
 * settled and unsold auctions are history: showing one in a list of things to
 * bid on would be an invitation to click on nothing.
 *
 * `publiclyVisible` comes first in every query, so a draft cannot be reached
 * through a filter or a crafted query string.
 *
 * ENDING SOONEST FIRST, from `ends_at`. That is the auction's own authoritative
 * timestamp, not a countdown a browser has been running -- a listing ordered
 * by what a page thinks the time is would reorder itself differently for every
 * viewer.
 */
class AuctionDiscoveryQuery
{
    public const PER_PAGE = 12;

    /**
     * The states a customer can still take part in or wait for.
     *
     * @return list<AuctionStatus>
     */
    public static function openStatuses(): array
    {
        return [AuctionStatus::Live, AuctionStatus::Closing, AuctionStatus::Scheduled];
    }

    /**
     * @var array<string, string>
     */
    public const SORTS = [
        'ending_soon' => 'Ending soonest',
        'newest' => 'Newest',
        'highest_bid' => 'Highest bid',
    ];

    /**
     * @param  array{search?: string, category?: string, condition?: string, filter?: string, sort?: string}  $filters
     * @return LengthAwarePaginator<int, Auction>
     */
    public function paginate(array $filters, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $term = mb_substr(trim($filters['search'] ?? ''), 0, ProductDiscoveryQuery::MAX_SEARCH_LENGTH);

        $query = Auction::query()
            ->publiclyVisible()
            // Eager loaded: a listing of twelve auctions would otherwise ask
            // the database for twelve products, twelve brands and twelve bids.
            ->with(['product.brand', 'product.category', 'highestBid'])
            ->when($term !== '', fn (Builder $q) => $q->whereHas(
                'product',
                fn (Builder $p) => $p->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$term}%"));
                }),
            ))
            ->when(($filters['category'] ?? '') !== '', fn (Builder $q) => $q->whereHas(
                'product.category',
                fn (Builder $c) => $c->where('slug', $filters['category']),
            ))
            ->when(($filters['condition'] ?? '') !== '', fn (Builder $q) => $q->whereHas(
                'product',
                fn (Builder $p) => $p->where('condition', $filters['condition']),
            ));

        $query = $this->scopeToFilter($query, $filters['filter'] ?? 'open');

        $this->sort($query, $filters['sort'] ?? 'ending_soon');

        return $query->paginate($perPage);
    }

    /**
     * Auctions worth putting on the homepage.
     *
     * @return Collection<int, Auction>
     */
    public function endingSoonest(int $limit = 4): Collection
    {
        return Auction::query()
            ->publiclyVisible()
            ->with(['product.brand', 'highestBid'])
            ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Closing])
            ->orderByRaw('ends_at IS NULL, ends_at ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * How many auctions a customer could take part in right now.
     */
    public function openCount(): int
    {
        return Auction::query()
            ->publiclyVisible()
            ->whereIn('status', self::openStatuses())
            ->count();
    }

    /**
     * @param  Builder<Auction>  $query
     * @return Builder<Auction>
     */
    private function scopeToFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            // Finished, in every sense -- settled, unsold, cancelled or
            // forfeited. Kept reachable so somebody can look back at one, and
            // never mixed into the list of things to bid on.
            'ended' => $query->whereNotIn('status', self::openStatuses()),
            'all' => $query,
            default => $query->whereIn('status', self::openStatuses()),
        };
    }

    /**
     * @param  Builder<Auction>  $query
     */
    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('id'),
            // The projection, which is what a listing is for. Nothing is
            // decided from it -- the engine reads the bid records when it
            // matters.
            'highest_bid' => $query->orderByRaw('highest_bid_credits IS NULL, highest_bid_credits DESC')
                ->orderByDesc('id'),
            // From the auction's own timestamp. A browser countdown has no say
            // in the order of a list.
            default => $query->orderByRaw('ends_at IS NULL, ends_at ASC')->orderByDesc('id'),
        };
    }
}

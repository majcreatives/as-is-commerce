<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\ValueObjects;

use App\Models\Auction;
use App\Models\Product;

/**
 * What a customer can actually do with a product right now.
 *
 * WHY THIS EXISTS, AND IT IS NOT COSMETIC. `Product::availableStock()` is
 * on-hand less reserved, and a live auction reserves the unit it is selling.
 * So a product with exactly one unit and an auction running on it has zero
 * available stock -- and asking the catalog alone would render "Out of stock"
 * on an item anybody can bid for or buy outright this minute.
 *
 * That is the same confusion the auction stage had to correct once already,
 * arriving here in a different form. The answer is the same: when an auction
 * holds the unit, the auction's own state is authoritative about whether the
 * product can be had, and catalog availability answers only for products no
 * auction is holding.
 *
 * READ-ONLY, AND DERIVED. Nothing here is stored, nothing is a second source
 * of truth, and nothing decides anything. Every purchase path re-checks its
 * own rules against locked rows when it actually runs; this exists so a page
 * can describe the situation honestly before the customer clicks.
 */
final readonly class ListingAvailability
{
    private function __construct(
        /** The auction currently holding this product, if one is. */
        public ?Auction $auction,
        /** Whether a customer could buy this outright right now. */
        public bool $canBuyNow,
        /** Whether a customer could bid on it right now. */
        public bool $canBid,
        /** Whether it is worth showing at all as obtainable. */
        public bool $obtainable,
        /** What to call this state on a card. */
        public string $label,
    ) {}

    /**
     * Work out what is true for one product.
     *
     * The auction, when there is one, must already be filtered to a currently
     * relevant one -- live, closing or scheduled. A finished auction is not a
     * reason to say anything about a product's availability, and a stale
     * highest bid must never reach a card.
     */
    public static function for(Product $product, ?Auction $auction = null): self
    {
        if ($auction !== null) {
            // The auction holds the unit, so it -- not the catalog -- says
            // whether this product can be had.
            $bidsOpen = $auction->status->acceptsBids();
            $buyNowOpen = $auction->status->acceptsBuyNow()
                && $auction->buyNowEnabled()
                && ! $auction->endedByBuyNow()
                // The unit still has to exist. Available stock is zero by
                // design here, which is exactly why this asks about on-hand.
                && $product->stock_on_hand > 0;

            return new self(
                auction: $auction,
                canBuyNow: $buyNowOpen,
                canBid: $bidsOpen,
                obtainable: $bidsOpen || $buyNowOpen,
                label: match (true) {
                    $bidsOpen => 'Auction live',
                    $auction->status->isOpen() => 'Auction starts soon',
                    default => 'Auction closing',
                },
            );
        }

        // No auction is holding it, so the catalog answers. `isPurchasable()`
        // is the domain's own question -- active, and something available --
        // asked rather than re-derived.
        $purchasable = $product->isPurchasable();

        return new self(
            auction: null,
            canBuyNow: $purchasable,
            canBid: false,
            obtainable: $purchasable,
            label: $purchasable ? 'Buy now' : 'Currently unavailable',
        );
    }

    /**
     * Whether this listing has a currently relevant auction at all.
     */
    public function hasAuction(): bool
    {
        return $this->auction !== null;
    }

    /**
     * The standing highest bid, in credits, or null.
     *
     * Null rather than zero when nobody has bid: "0 credits" reads like a
     * price of nothing, and this is a count of what somebody committed.
     *
     * Read from the auction's projection, which is the figure listings are
     * for. A page deciding anything -- who won, what a bid must beat -- reads
     * the bid records instead.
     */
    public function highestBidCredits(): ?int
    {
        if ($this->auction === null || $this->auction->highest_bid_credits === null) {
            return null;
        }

        return $this->auction->highest_bid_credits > 0
            ? $this->auction->highest_bid_credits
            : null;
    }

    public function badgeClasses(): string
    {
        return match (true) {
            $this->canBid => 'bg-accent-50 text-accent-900 ring-accent-200',
            $this->obtainable => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            default => 'bg-slate-100 text-slate-600 ring-slate-200',
        };
    }
}

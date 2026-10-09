<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Marketplace\RecentlyViewed;
use App\Domain\Marketplace\ValueObjects\ListingAvailability;
use App\Models\Product;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One product's public page.
 *
 * The lookup is scoped to publicly visible products rather than fetching by
 * slug and then checking, so a draft or archived listing returns 404 rather
 * than existing at a guessable URL that merely refuses to render.
 *
 * AVAILABILITY COMES FROM AUTHORITATIVE STATE, NOT FROM STOCK ARITHMETIC. A
 * live auction reserves the unit it is selling, so a product with one unit and
 * an auction running on it has zero *available* stock -- and a page that asked
 * the catalog alone would tell a customer an item was out of stock while an
 * auction for it was open in the next tab.
 *
 * AN ACTIVE AUCTION LEADS THE PAGE. When one holds this unit it owns both ways
 * of acquiring the product -- the bidding and the Buy Now, whose price carries
 * the bidder's credit discount -- so it is presented first and the outright
 * price follows it. What triggers that is an auction that actually exists and
 * is currently relevant, never `products.auction_eligible`, which says only
 * that an administrator MAY put this product into the auction channel.
 *
 * The page still does no bidding. Placing a bid is two deliberate actions
 * validated against a locked auction row, and a second copy of that form here
 * would be a second place to keep correct; this links to the auction room.
 *
 * ADDING TO THE CART RESERVES NOTHING. For a product no auction is holding,
 * the page offers a quantity and "Add to cart". The line is intent with a
 * durable home; the order and its reservation happen atomically when the
 * customer places the cart. Nothing is sold until a payment is verified with
 * the provider.
 *
 * The browser sends a slug and a quantity. It never sends a price, a total or
 * a discount.
 */
#[Layout('components.layouts.app')]
class ProductDetail extends Component
{
    public Product $product;

    /**
     * How many the customer wants, sent alongside the product when they add
     * it to their cart. A quantity, nothing more: the price is the product
     * row's and is never read from the browser.
     */
    public int $quantity = 1;

    public function mount(string $slug, RecentlyViewed $recently): void
    {
        $this->product = Product::query()
            ->publiclyVisible()
            ->with(['brand', 'category.parent', 'images'])
            ->where('slug', $slug)
            ->firstOrFail();

        // A page that was actually served is the one fact that reliably means
        // "visited". Mount runs once per page load -- render would also run on
        // every livewire update -- and re-runs on wire:navigate navigation, so
        // the history follows visits and never double-records one.
        $recently->remember($this->product);
    }

    /**
     * Put the product on the customer's cart.
     *
     * Editing a cart updates intent and nothing else -- no reservation, no
     * order, no ledger. Quantity is capped at the ledger's available stock
     * and the line is validated again, against locked rows, when the order is
     * placed. Nothing this page rendered is trusted; somebody may have bought
     * the last units while it was on screen.
     */
    public function addToCart(AddToCart $add, ProductDiscoveryQuery $products): ?RedirectResponse
    {
        $this->authorize('checkout.create');

        // An auction holding this product owns the path for it: the price
        // carries the bidder's credit discount. Send them to the auction
        // rather than building a second, discount-free line on the same unit.
        $auction = $products->activeAuctionFor($this->product);

        if ($auction !== null) {
            return redirect()->route('auctions.show', $auction);
        }

        try {
            $add->handle(buyer: auth()->user(), product: $this->product->fresh(), quantity: $this->quantity);
        } catch (DomainException $e) {
            // The domain's own words: they say which rule stopped it, which is
            // more use than a generic failure to somebody about to add to a
            // cart.
            $this->addError('cart', $e->getMessage());
            $this->product->refresh();

            return null;
        }

        session()->flash('cart-added', "{$this->product->name} was added to your cart.");

        return redirect()->route('cart.show');
    }

    public function render(
        ProductDiscoveryQuery $products,
        AuctionClock $clock,
        RecentlyViewed $recently,
    ): View {
        $auction = $products->activeAuctionFor($this->product);
        $availability = ListingAvailability::for($this->product, $auction);

        // The visitor's own path, newest first, with the open product left out
        // because it already has the whole page. Read-only over the session.
        $recentlyViewed = $recently->recent(excludeId: $this->product->id);

        $related = $products->related($this->product);

        // ONE batched pass reads availability for every card below -- similar
        // and recently viewed -- so a unit that an auction is holding shows the
        // bid instead of an "unavailable" label whichever strip it appears in.
        $cardAvailability = $products->availabilityFor($related->concat($recentlyViewed));

        return view('livewire.catalog.product-detail', [
            'availability' => $availability,
            'auction' => $auction,

            // Worked out on the server, for a countdown to display. It decides
            // nothing: the auction ends when `ends_at` says so and the sweep
            // notices, so when this reaches zero the page announces no
            // outcome. Null before an auction has opened, which is honest
            // rather than a zero that would read as "closing".
            'secondsRemaining' => $auction === null ? null : $clock->secondsRemaining($auction),

            'related' => $related,
            'relatedAvailability' => $cardAvailability,
            'recentlyViewed' => $recentlyViewed,
        ])
            ->title($this->product->name)
            ->layoutData([
                'description' => $this->product->short_description
                    ?? $this->fallbackDescription($availability),
                'ogImage' => $this->productImageUrl(),
                // Truthful only: what it is, its condition, and the price it
                // can actually be bought at. No rating, no review count, no
                // availability claim the page cannot stand behind.
                'structuredData' => $this->structuredData($availability),
            ]);
    }

    /**
     * What a product with no written description says about itself instead.
     *
     * The previous fallback ended "... buy now on <app>." for every product
     * that had no `short_description`, which is a claim rather than a
     * description -- and a false one whenever the listing could not be bought
     * outright, because an auction is holding the unit, or the product is out of
     * stock. Indexed metadata asserting an availability the page contradicts is
     * worse than saying nothing: a search result is the one place a customer
     * cannot see the page that would correct it.
     *
     * So the sentence is built from the same {@see ListingAvailability} the page
     * renders, and its labels are the server's own. No price is named here
     * either -- a figure in a search snippet goes stale the moment the price
     * changes, and the page is where the current one belongs.
     *
     * The auction is named in preference to Buy Now, because a live auction
     * accepts Buy Now too and both are true at once. Bidding is the more
     * specific fact and the one this page leads with; the buy-now button is
     * still rendered, so nothing is hidden by describing the auction instead.
     */
    private function fallbackDescription(ListingAvailability $availability): string
    {
        $howItIsGot = match (true) {
            $availability->canBid => 'In an auction, open for bidding with credits',
            $availability->canBuyNow => 'Available to buy now',
            default => 'Currently unavailable',
        };

        return sprintf(
            '%s (%s). %s on %s.',
            $this->product->name,
            $this->product->condition->label(),
            $howItIsGot,
            config('app.name'),
        );
    }

    /**
     * Product metadata for search engines.
     *
     * Only facts the platform can prove. Availability follows the same
     * authoritative reading the page shows a human, so a search result cannot
     * say "in stock" about something the page itself calls unavailable --
     * which is why the caller's reading is passed in rather than a second one
     * being resolved here from a fresh query.
     *
     * @return array<string, mixed>
     */
    private function structuredData(ListingAvailability $availability): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $this->product->name,
            'sku' => $this->product->sku,
            'description' => $this->product->short_description,
            'brand' => $this->product->brand?->name,
            'image' => $this->productImageUrl(),
            'offers' => [
                '@type' => 'Offer',
                'url' => route('products.show', $this->product->slug),
                'priceCurrency' => $this->product->currency,
                // Decimal string from the integer minor units, never a float.
                'price' => $this->product->buyNowPrice()->toDecimalString(),
                'availability' => $availability->obtainable
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ],
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The product's image as an absolute URL, for crawlers and share cards.
     *
     * The featured gallery image already comes back absolute from the storage
     * disk. The legacy single-column path is relative, so it is completed
     * against the application URL -- an og:image that crawlers resolve is the
     * whole point of emitting one.
     */
    private function productImageUrl(): ?string
    {
        $image = $this->product->image();

        if ($image === null) {
            return null;
        }

        return Str::startsWith($image, ['http://', 'https://']) ? $image : url($image);
    }
}

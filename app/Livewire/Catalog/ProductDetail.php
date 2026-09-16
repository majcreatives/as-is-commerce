<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Marketplace\ValueObjects\ListingAvailability;
use App\Models\Product;
use DomainException;
use Illuminate\Http\RedirectResponse;
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

    public function mount(string $slug): void
    {
        $this->product = Product::query()
            ->publiclyVisible()
            ->with(['brand', 'category.parent'])
            ->where('slug', $slug)
            ->firstOrFail();
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

    public function render(ProductDiscoveryQuery $products): View
    {
        $auction = $products->activeAuctionFor($this->product);

        return view('livewire.catalog.product-detail', [
            'availability' => ListingAvailability::for($this->product, $auction),
            'auction' => $auction,
            'related' => $products->related($this->product),
        ])
            ->title($this->product->name)
            ->layoutData([
                'description' => $this->product->short_description
                    ?? $this->product->name.' — buy now on '.config('app.name').'.',
                'ogImage' => $this->product->image_path,
                // Truthful only: what it is, its condition, and the price it
                // can actually be bought at. No rating, no review count, no
                // availability claim the page cannot stand behind.
                'structuredData' => $this->structuredData($auction !== null),
            ]);
    }

    /**
     * Product metadata for search engines.
     *
     * Only facts the platform can prove. Availability follows the same
     * authoritative reading the page shows a human, so a search result cannot
     * say "in stock" about something the page itself calls unavailable.
     *
     * @return array<string, mixed>
     */
    private function structuredData(bool $hasAuction): array
    {
        $availability = ListingAvailability::for(
            $this->product,
            $hasAuction ? app(ProductDiscoveryQuery::class)->activeAuctionFor($this->product) : null,
        );

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $this->product->name,
            'sku' => $this->product->sku,
            'description' => $this->product->short_description,
            'brand' => $this->product->brand?->name,
            'image' => $this->product->image_path === null ? null : url($this->product->image_path),
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
}

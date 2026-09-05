<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Marketplace\ValueObjects\ListingAvailability;
use App\Domain\Orders\Actions\StartBuyNowCheckout;
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
 * BUY NOW OPENS A CHECKOUT; IT DOES NOT BUY ANYTHING. The action creates an
 * order at a price the server computes and freezes, and the customer then pays
 * it. Nothing is sold until that payment is verified with the provider, which
 * is why the button says what it does and the page does not claim the product
 * is theirs.
 *
 * The browser sends a slug. It never sends a price, a quantity or a discount.
 */
#[Layout('components.layouts.app')]
class ProductDetail extends Component
{
    public Product $product;

    public function mount(string $slug): void
    {
        $this->product = Product::query()
            ->publiclyVisible()
            ->with(['brand', 'category.parent'])
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * Open a checkout to buy this product outright.
     *
     * Every rule is the domain's, checked against locked rows: whether the
     * product is purchasable, whether stock is genuinely available, whether
     * this customer already has a checkout open on it. Nothing this page
     * rendered is trusted -- somebody may have bought the last one while it
     * was on screen.
     */
    public function buyNow(StartBuyNowCheckout $checkout, ProductDiscoveryQuery $products): ?RedirectResponse
    {
        $this->authorize('checkout.create');

        // An auction holding this product owns the Buy Now path for it: the
        // price carries the bidder's credit discount and completing it ends
        // the auction. Sending them to the auction rather than opening a
        // second, discount-free checkout on the same unit.
        $auction = $products->activeAuctionFor($this->product);

        if ($auction !== null) {
            return redirect()->route('auctions.show', $auction);
        }

        try {
            $order = $checkout->handle(buyer: auth()->user(), product: $this->product->fresh());
        } catch (DomainException $e) {
            // The domain's own words: they say which rule stopped it, which is
            // more use than a generic failure to somebody about to spend money.
            $this->addError('checkout', $e->getMessage());
            $this->product->refresh();

            return null;
        }

        return redirect()->route('checkout.show', $order);
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

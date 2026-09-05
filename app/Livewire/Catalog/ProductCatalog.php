<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Enums\ProductCondition;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public product catalog.
 *
 * A thin component over {@see ProductDiscoveryQuery}. The filters live here
 * because they are interface state; the queries live there because a listing
 * page is not the place to keep a description of what "publicly visible"
 * means.
 *
 * AVAILABILITY IS RESOLVED FOR THE WHOLE PAGE AT ONCE, and from authoritative
 * auction state rather than from stock arithmetic. A live auction reserves the
 * unit it is selling, so asking the catalog alone would print "out of stock"
 * on every auctioned product.
 *
 * Prices shown are the product's own Buy Now price in GHS. Nothing here reads
 * a credit balance, and no bid figure reaches a card except through the
 * currently relevant auction the query resolved.
 */
#[Layout('components.layouts.app')]
#[Title('Shop')]
class ProductCatalog extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $brand = '';

    #[Url]
    public string $condition = '';

    #[Url]
    public bool $availableOnly = false;

    #[Url]
    public string $sort = 'newest';

    /**
     * @var array<string, string>
     */
    public const SORTS = [
        'newest' => 'Newest first',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'name' => 'Name A–Z',
    ];

    public function updated(string $property): void
    {
        // Any filter change returns to page one; staying on page 7 of a
        // narrower result set shows an empty page for no reason.
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'brand', 'condition', 'availableOnly');
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->category !== ''
            || $this->brand !== ''
            || $this->condition !== ''
            || $this->availableOnly;
    }

    public function render(ProductDiscoveryQuery $products): View
    {
        $page = $products->paginate([
            'search' => $this->search,
            'category' => $this->category,
            'brand' => $this->brand,
            'condition' => $this->condition,
            'availableOnly' => $this->availableOnly,
            'sort' => $this->sort,
        ]);

        return view('livewire.catalog.product-catalog', [
            'products' => $page,
            // One query for the page rather than one per card.
            'availability' => $products->availabilityFor(collect($page->items())),
            'categories' => $products->categories(),
            'brands' => $products->brands(),
            'conditions' => ProductCondition::cases(),
            'sorts' => self::SORTS,
        ]);
    }
}

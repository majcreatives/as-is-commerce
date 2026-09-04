<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public product catalog.
 *
 * Every query starts from the publiclyVisible scope, so a draft, inactive or
 * archived product cannot appear through a filter, a search term or a crafted
 * query string. Visibility is decided by the scope, not by remembering to add
 * a status check at each call site.
 *
 * Prices shown are the product's own Buy Now price in GHS. Nothing here reads
 * a credit balance, a bid or a credit package.
 */
#[Layout('components.layouts.app')]
#[Title('Products')]
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
    public bool $inStockOnly = false;

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
        $this->reset('search', 'category', 'brand', 'condition', 'inStockOnly');
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->category !== ''
            || $this->brand !== ''
            || $this->condition !== ''
            || $this->inStockOnly;
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Product::query()
            ->publiclyVisible()
            ->with(['brand', 'category'])
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($term): void {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('short_description', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%");
            }))
            ->when($this->category !== '', fn (Builder $q) => $q->whereHas(
                'category',
                fn (Builder $c) => $c->where('slug', $this->category),
            ))
            ->when($this->brand !== '', fn (Builder $q) => $q->whereHas(
                'brand',
                fn (Builder $b) => $b->where('slug', $this->brand),
            ))
            ->when($this->condition !== '', fn (Builder $q) => $q->where('condition', $this->condition))
            ->when($this->inStockOnly, fn (Builder $q) => $q->inStock())
            ->tap(fn (Builder $q) => $this->applySort($q))
            ->paginate(12);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'price_asc' => $query->orderBy('buy_now_price_minor'),
            'price_desc' => $query->orderByDesc('buy_now_price_minor'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * Categories that actually hold something a customer can see.
     *
     * Offering a filter that returns nothing is worse than not offering it.
     *
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return Category::query()
            ->active()
            // The status list rather than the scope: static analysis cannot
            // resolve the related model through whereHas, and both read the
            // same definition, so the visibility rule still lives in one place.
            ->whereHas('products', fn (Builder $q) => $q->whereIn(
                'status',
                ProductStatus::publiclyVisibleCases(),
            ))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Brand>
     */
    public function brands(): Collection
    {
        return Brand::query()
            ->active()
            // The status list rather than the scope: static analysis cannot
            // resolve the related model through whereHas, and both read the
            // same definition, so the visibility rule still lives in one place.
            ->whereHas('products', fn (Builder $q) => $q->whereIn(
                'status',
                ProductStatus::publiclyVisibleCases(),
            ))
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.catalog.product-catalog', [
            'products' => $this->products(),
            'categories' => $this->categories(),
            'brands' => $this->brands(),
            'conditions' => ProductCondition::cases(),
            'sorts' => self::SORTS,
        ]);
    }
}

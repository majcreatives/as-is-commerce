<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Catalog;

use App\Domain\Catalog\Services\ProductService;
use App\Domain\Shared\Money\Money;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Product administration.
 *
 * The price is entered in cedis and converted to integer pesewas on save, so
 * the form never holds a float. Status is not part of the form: it moves only
 * through the lifecycle actions, which refuse illegal transitions.
 *
 * Stock is not editable here either. It has its own screen, because every
 * change to it has to be a recorded movement with a reason.
 */
#[Layout('components.layouts.app')]
#[Title('Products')]
class ProductManager extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public ?int $editingId = null;

    public bool $showForm = false;

    // Form
    public string $sku = '';

    public string $name = '';

    public string $slug = '';

    public string $short_description = '';

    public string $description = '';

    public ?int $category_id = null;

    public ?int $brand_id = null;

    public string $condition = 'new';

    public string $price = '';

    // The auction channel is opt-in per product. This checkbox is the
    // administrator's explicit statement of intent. It is deliberately a
    // separate flag from status and price: cataloguing a product, and giving it
    // a Buy Now price, grants nothing about the auction channel on its own.
    public bool $auction_eligible = false;

    public function mount(): void
    {
        $this->authorize('products.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('products.create');

        $this->reset('editingId', 'sku', 'name', 'slug', 'short_description', 'description', 'brand_id', 'price', 'auction_eligible');
        $this->condition = 'new';
        $this->category_id = Category::query()->active()->value('id');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('products.update');

        $product = Product::findOrFail($id);

        abort_unless($product->status->isEditable(), 403, 'An archived product cannot be edited.');

        $this->editingId = $product->id;
        $this->sku = $product->sku;
        $this->name = $product->name;
        $this->slug = $product->slug;
        $this->short_description = $product->short_description ?? '';
        $this->description = $product->description ?? '';
        $this->category_id = $product->category_id;
        $this->brand_id = $product->brand_id;
        $this->condition = $product->condition->value;
        $this->price = $product->buyNowPrice()->toDecimalString();
        $this->auction_eligible = (bool) $product->auction_eligible;
        $this->showForm = true;
    }

    public function save(ProductService $products): void
    {
        $this->authorize($this->editingId === null ? 'products.create' : 'products.update');

        $validated = $this->validate([
            // Optional: the service derives a readable one when it is blank,
            // which is what the field's hint promises.
            'sku' => [
                'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_]+$/',
                Rule::unique('products', 'sku')->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable', 'string', 'max:200', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique('products', 'slug')->ignore($this->editingId),
            ],
            'short_description' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'condition' => ['required', Rule::enum(ProductCondition::class)],
            // A decimal string validated by shape, so no float is involved.
            'price' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            // A deliberate administrator decision. Unchecked (false) by
            // default, which is exactly the channel's rule: nothing gets
            // auctionable just by existing.
            'auction_eligible' => ['required', 'boolean'],
        ], [
            'sku.regex' => 'A SKU may contain letters, numbers, hyphens and underscores only.',
            'slug.regex' => 'A slug may contain lowercase letters, numbers and hyphens only.',
            'price.regex' => 'Enter an amount such as 5500 or 5500.00, with no currency symbol.',
        ]);

        $currency = settings()->getString('currency', 'GHS') ?? 'GHS';
        $priceMinor = Money::fromDecimalString($validated['price'], $currency)->minor;

        if ($priceMinor <= 0) {
            $this->addError('price', 'A product must cost more than zero.');

            return;
        }

        $attributes = [
            'sku' => $validated['sku'] !== '' ? $validated['sku'] : null,
            'name' => $validated['name'],
            'slug' => $validated['slug'] !== '' ? $validated['slug'] : $validated['name'],
            'short_description' => $validated['short_description'] !== '' ? $validated['short_description'] : null,
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'category_id' => $validated['category_id'],
            'brand_id' => $validated['brand_id'],
            'condition' => $validated['condition'],
            'auction_eligible' => $validated['auction_eligible'],
            'buy_now_price_minor' => $priceMinor,
            'currency' => $currency,
        ];

        try {
            if ($this->editingId === null) {
                $created = $products->create($attributes, auth()->user());
                session()->flash('status', "Product [{$created->sku}] created as a draft. Activate it when ready.");
            } else {
                $products->update(Product::findOrFail($this->editingId), $attributes, auth()->user());
                session()->flash('status', 'Product updated.');
            }
        } catch (DomainException $e) {
            $this->addError('sku', $e->getMessage());

            return;
        }

        $this->reset('editingId', 'sku', 'name', 'slug', 'short_description', 'description', 'brand_id', 'price', 'showForm');
    }

    public function changeStatus(int $id, string $status, ProductService $products): void
    {
        $target = ProductStatus::from($status);

        $this->authorize($target === ProductStatus::Archived ? 'products.archive' : 'products.activate');

        try {
            $products->transitionTo(Product::findOrFail($id), $target, auth()->user());
        } catch (DomainException $e) {
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        session()->flash('status', 'Product status updated.');
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Product::query()
            ->with(['category', 'brand', 'images'])
            ->when($term !== '', fn ($q) => $q->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%");
            }))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('id')
            ->paginate(20);
    }

    /**
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return Category::orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Brand>
     */
    public function brands(): Collection
    {
        return Brand::orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.catalog.product-manager', [
            'products' => $this->products(),
            'categories' => $this->categories(),
            'brands' => $this->brands(),
            'conditions' => ProductCondition::cases(),
            'statuses' => ProductStatus::cases(),
        ]);
    }
}

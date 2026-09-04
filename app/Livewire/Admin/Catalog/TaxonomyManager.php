<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Categories and brands, on one screen.
 *
 * They are kept together because they are the same kind of thing -- small
 * taxonomy records that organise the catalog -- and splitting them would mean
 * two nearly identical screens.
 *
 * Neither can be deleted. A category holding products or child categories is
 * refused by the database, and archiving is the supported way to retire
 * either, so nothing is ever orphaned.
 */
#[Layout('components.layouts.app')]
#[Title('Categories & brands')]
class TaxonomyManager extends Component
{
    #[Url]
    public string $tab = 'categories';

    public ?int $editingId = null;

    public bool $showForm = false;

    public string $name = '';

    public string $description = '';

    public ?int $parent_id = null;

    public int $sort_order = 0;

    public function mount(): void
    {
        $this->authorize('categories.view');
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
        $this->cancel();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'description', 'parent_id', 'sort_order', 'showForm');
    }

    public function create(): void
    {
        $this->authorize($this->isCategories() ? 'categories.create' : 'brands.create');

        $this->reset('editingId', 'name', 'description', 'parent_id', 'sort_order');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize($this->isCategories() ? 'categories.update' : 'brands.update');

        if ($this->isCategories()) {
            $category = Category::findOrFail($id);
            $this->parent_id = $category->parent_id;
            $this->sort_order = $category->sort_order;
            $this->name = $category->name;
            $this->description = $category->description ?? '';
        } else {
            $brand = Brand::findOrFail($id);
            $this->name = $brand->name;
            $this->description = $brand->description ?? '';
        }

        $this->editingId = $id;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize(
            $this->isCategories()
                ? ($this->editingId === null ? 'categories.create' : 'categories.update')
                : ($this->editingId === null ? 'brands.create' : 'brands.update'),
        );

        $this->isCategories() ? $this->saveCategory() : $this->saveBrand();
    }

    private function saveCategory(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        // A category cannot be its own parent. The database cannot express
        // this -- MySQL forbids a CHECK on an auto-increment column -- so it
        // is enforced here.
        if ($this->editingId !== null && $validated['parent_id'] === $this->editingId) {
            $this->addError('parent_id', 'A category cannot be its own parent.');

            return;
        }

        if ($this->editingId !== null && $validated['parent_id'] !== null
            && $this->wouldCreateCycle($this->editingId, $validated['parent_id'])) {
            $this->addError('parent_id', 'That would place the category inside one of its own descendants.');

            return;
        }

        $category = $this->editingId === null ? new Category : Category::findOrFail($this->editingId);

        $category->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'parent_id' => $validated['parent_id'],
            'sort_order' => $validated['sort_order'],
        ]);

        if (! $category->exists) {
            $category->slug = $this->uniqueSlug(Category::class, $validated['name']);
            $category->status = CatalogStatus::Active;
            $category->created_by = auth()->id();
        }

        $category->updated_by = auth()->id();
        $category->save();

        $this->cancel();
        session()->flash('status', 'Category saved.');
    }

    private function saveBrand(): void
    {
        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('brands', 'name')->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $brand = $this->editingId === null ? new Brand : Brand::findOrFail($this->editingId);

        $brand->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
        ]);

        if (! $brand->exists) {
            $brand->slug = $this->uniqueSlug(Brand::class, $validated['name']);
            $brand->status = CatalogStatus::Active;
            $brand->created_by = auth()->id();
        }

        $brand->updated_by = auth()->id();
        $brand->save();

        $this->cancel();
        session()->flash('status', 'Brand saved.');
    }

    public function setStatus(int $id, string $status): void
    {
        $target = CatalogStatus::from($status);
        $activating = $target === CatalogStatus::Active;

        $this->authorize(match (true) {
            $this->isCategories() && $activating => 'categories.activate',
            $this->isCategories() => 'categories.archive',
            $activating => 'brands.activate',
            default => 'brands.archive',
        });

        $record = $this->isCategories() ? Category::findOrFail($id) : Brand::findOrFail($id);
        $record->status = $target;
        $record->updated_by = auth()->id();
        $record->save();

        session()->flash('status', 'Status updated.');
    }

    /**
     * Whether making $parentId the parent of $categoryId would form a loop.
     */
    private function wouldCreateCycle(int $categoryId, int $parentId): bool
    {
        $current = Category::find($parentId);
        $depth = 0;

        while ($current !== null && $depth < 10) {
            if ($current->id === $categoryId) {
                return true;
            }

            $current = $current->parent_id === null ? null : Category::find($current->parent_id);
            $depth++;
        }

        return false;
    }

    /**
     * @param  class-string<Category|Brand>  $model
     */
    private function uniqueSlug(string $model, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while ($model::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function isCategories(): bool
    {
        return $this->tab === 'categories';
    }

    /**
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return Category::with('parent')->withCount('products')
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Brand>
     */
    public function brands(): Collection
    {
        return Brand::withCount('products')->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.catalog.taxonomy-manager', [
            'categories' => $this->categories(),
            'brands' => $this->brands(),
            'parentOptions' => Category::orderBy('name')->get(),
        ]);
    }
}

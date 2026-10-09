<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Content;

use App\Enums\CatalogStatus;
use App\Models\BlogCategory;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Blog categories and tags, on one screen.
 *
 * They are the vocabulary of the blog, not of the catalog: they organise posts
 * and are kept apart from the catalogue's `Categories` screen so the two cannot
 * be mistaken for one another. They are the same kind of thing though -- small
 * taxonomy records an administrator manages -- so they share a screen rather
 * than two nearly identical ones.
 *
 * DELETE IS AVAILABLE AND IT IS PERMANENT. `BlogCategory` and `Tag` have a
 * delete on their own `*.delete` permission -- the opposite end of archiving.
 * A deleted category leaves its posts behind with `category_id` cleared, never
 * a dangling reference (the column is nullable); a deleted tag's link to every
 * post is removed with it. Archive is the soft option that keeps the row and
 * the audit history; Delete removes the row entirely.
 *
 * SLUGS ARE DERIVED, NOT TYPED. A category or tag's web address grows from its
 * name -- {@see self::uniqueSlug()} guarantees it is unique before saving -- so
 * the name is the only field a person has to get right, which is what the form
 * offers.
 */
#[Layout('components.layouts.app')]
#[Title('Blog categories & tags')]
class BlogTaxonomyManager extends Component
{
    #[Url]
    public string $tab = 'categories';

    /**
     * The tab names, and the view permission each one answers to.
     *
     * Written out per tab rather than derived, because a prefix that is correct
     * only "most of the time" is how a tab ends up authorizing the wrong thing.
     *
     * @var array<string, string>
     */
    private const TAB_PERMISSION = [
        'categories' => 'blog_categories.view',
        'tags' => 'blog_tags.view',
    ];

    public ?int $editingId = null;

    public bool $showForm = false;

    public string $name = '';

    public string $description = '';

    public int $sort_order = 0;

    public function mount(): void
    {
        // WHICH TAB THIS PERSON CAN SEE. Checked rather than assumed, so the
        // screen opens on a tab the caller may actually view instead of refusing
        // a request the nav has already offered: the nav links here on the
        // strength of EITHER view permission, so someone who manages only one of
        // the two would otherwise be handed a link that leads to a 403.
        $viewable = array_values(array_filter(
            array_keys(self::TAB_PERMISSION),
            fn (string $tab): bool => Gate::check(self::TAB_PERMISSION[$tab]),
        ));

        abort_if($viewable === [], 403);

        if (! in_array($this->tab, $viewable, true)) {
            $this->tab = $viewable[0];
        }
    }

    public function switchTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TAB_PERMISSION), 404);

        $this->authorize(self::TAB_PERMISSION[$tab]);

        $this->tab = $tab;
        $this->cancel();
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'description', 'sort_order', 'showForm');
    }

    public function create(): void
    {
        $this->authorize($this->isCategories() ? 'blog_categories.create' : 'blog_tags.create');

        $this->reset('editingId', 'name', 'description', 'sort_order');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize($this->isCategories() ? 'blog_categories.update' : 'blog_tags.update');

        $record = $this->isCategories() ? BlogCategory::findOrFail($id) : Tag::findOrFail($id);
        $this->name = $record->name;
        $this->description = $record->description ?? '';
        $this->sort_order = $record->sort_order ?? 0;

        $this->editingId = $id;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize(
            $this->isCategories()
                ? ($this->editingId === null ? 'blog_categories.create' : 'blog_categories.update')
                : ($this->editingId === null ? 'blog_tags.create' : 'blog_tags.update'),
        );

        $this->isCategories() ? $this->saveCategory() : $this->saveTag();
    }

    private function saveCategory(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $category = $this->editingId === null ? new BlogCategory : BlogCategory::findOrFail($this->editingId);

        $category->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
            'sort_order' => $validated['sort_order'],
        ]);

        if (! $category->exists) {
            $category->slug = $this->uniqueSlug(BlogCategory::class, $validated['name']);
            $category->status = CatalogStatus::Active;
            $category->created_by = auth()->id();
        }

        $category->updated_by = auth()->id();
        $category->save();

        $this->cancel();
        session()->flash('status', 'Blog category saved.');
    }

    private function saveTag(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $tag = $this->editingId === null ? new Tag : Tag::findOrFail($this->editingId);

        $tag->fill(['name' => $validated['name']]);

        if (! $tag->exists) {
            $tag->slug = $this->uniqueSlug(Tag::class, $validated['name']);
            $tag->status = CatalogStatus::Active;
        }

        $tag->save();

        $this->cancel();
        session()->flash('status', 'Tag saved.');
    }

    public function setStatus(int $id, string $status): void
    {
        $target = CatalogStatus::from($status);
        $activating = $target === CatalogStatus::Active;

        $this->authorize(match (true) {
            $this->isCategories() && $activating => 'blog_categories.activate',
            $this->isCategories() => 'blog_categories.archive',
            $activating => 'blog_tags.activate',
            default => 'blog_tags.archive',
        });

        $record = $this->isCategories() ? BlogCategory::findOrFail($id) : Tag::findOrFail($id);
        $record->status = $target;
        $record->save();

        session()->flash('status', 'Status updated.');
    }

    /**
     * Permanently delete a category or tag.
     *
     * Gated on its own `*.delete` permission, separate from `archive`: retiring
     * a label is not the same act as removing it from existence. Both models
     * are safe to delete by construction -- a category's posts simply lose
     * their category reference (the column is nullable), and a tag's post links
     * go with the row -- and both log a `deleted` activity entry through their
     * model trait.
     */
    public function deleteRecord(int $id): void
    {
        $this->authorize($this->isCategories() ? 'blog_categories.delete' : 'blog_tags.delete');

        $record = $this->isCategories() ? BlogCategory::findOrFail($id) : Tag::findOrFail($id);
        $record->delete();

        session()->flash('status', 'Deleted permanently.');
    }

    /**
     * A slug for a taxonomy name that is not already taken.
     *
     * @param  class-string<BlogCategory|Tag>  $model
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
     * @return Collection<int, BlogCategory>
     */
    public function categories(): Collection
    {
        return BlogCategory::query()
            ->withCount('posts')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tags(): Collection
    {
        return Tag::query()
            ->withCount('posts')
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.admin.content.blog-taxonomy-manager', [
            'categories' => $this->categories(),
            'tags' => $this->tags(),
        ]);
    }
}

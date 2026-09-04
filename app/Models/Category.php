<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A place in the catalog, optionally nested inside another.
 *
 * Electronics contains Phones and Tablets. Categories are administered rather
 * than hard-coded, so the catalog can be reorganised without a deployment.
 *
 * Deletion is restricted in both directions -- a category holding products
 * cannot be deleted, and neither can one holding child categories -- so
 * nothing is ever silently orphaned. Retiring a category means archiving it.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int|null $parent_id
 * @property CatalogStatus $status
 * @property int $sort_order
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'description', 'parent_id', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * The category path, from the outermost ancestor inwards.
     *
     * Walked iteratively with a depth cap rather than recursively: a cycle in
     * the data would otherwise hang the request, and while the database
     * forbids a category being its own parent, it cannot forbid a longer loop.
     *
     * @return Collection<int, self>
     */
    public function ancestry(): Collection
    {
        /** @var Collection<int, self> $path */
        $path = collect([$this]);

        $current = $this;
        $depth = 0;

        while ($current->parent_id !== null && $depth < 10) {
            $parent = $current->parent()->first();

            if ($parent === null || $path->contains(fn (self $c): bool => $c->id === $parent->id)) {
                break;
            }

            $path->prepend($parent);
            $current = $parent;
            $depth++;
        }

        return $path;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('category')
            ->logOnly(['name', 'slug', 'parent_id', 'status', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Category [{$this->name}] was {$eventName}";
    }
}

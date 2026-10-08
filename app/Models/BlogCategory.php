<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\BlogCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A place in the blog a post belongs to.
 *
 * Deliberately not the catalogue's {@see Category}: a blog post sits in the
 * content tree, not the shop tree, and the two must never be mixed.
 *
 * A post has exactly one category, or none. Retiring a category means
 * archiving it; the post keeps its category reference because the foreign key
 * is nullable and null-on-delete.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property CatalogStatus $status
 * @property int $sort_order
 * @property string $posts_max_updated_at
 */
class BlogCategory extends Model
{
    /** @use HasFactory<BlogCategoryFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'description', 'sort_order'];

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
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'category_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('blog_category')
            ->logOnly(['name', 'slug', 'sort_order', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Blog category [{$this->name}] was {$eventName}";
    }
}

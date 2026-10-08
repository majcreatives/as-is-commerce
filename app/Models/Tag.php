<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A label attached to many blog posts, and many to a post.
 *
 * Tags are administered, not free-typed: the post form offers existing active
 * tags, so a tag exists because someone created it deliberately. Retiring one
 * means archiving it; the pivot keeps working from either direction.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property CatalogStatus $status
 * @property string $posts_max_updated_at
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['name', 'slug'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
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
            ->useLogName('tag')
            ->logOnly(['name', 'slug', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Tag [{$this->name}] was {$eventName}";
    }
}

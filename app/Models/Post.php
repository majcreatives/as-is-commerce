<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'body',
        'category_id',
        'meta_title',
        'meta_description',
        'primary_keyword',
        'secondary_keywords',
        'image_path',
        'active',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'published_at' => 'datetime',
        'secondary_keywords' => 'array',
    ];

    /**
     * @return BelongsTo<BlogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * The post's search keywords, as one deduplicated list.
     *
     * Null when no keyword has been supplied, so the layout can decide not to
     * emit a meta keywords tag rather than inventing one.
     *
     * @return list<string>|null
     */
    public function seoKeywords(): ?array
    {
        $keywords = [];

        if ($this->primary_keyword !== null && $this->primary_keyword !== '') {
            $keywords[] = $this->primary_keyword;
        }

        foreach (($this->secondary_keywords ?? []) as $keyword) {
            if (is_string($keyword) && trim($keyword) !== '' && ! in_array(trim($keyword), $keywords, true)) {
                $keywords[] = trim($keyword);
            }
        }

        return $keywords === [] ? null : $keywords;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('active', true)->whereNotNull('published_at');
    }

    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }
}

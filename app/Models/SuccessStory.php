<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SuccessStoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A success story told by a customer, published on the public site.
 *
 * Publishing is the `active` flag. `featured` is the narrower one: a story can
 * be listed on /success-stories without occupying a slot in the homepage block,
 * so a real backlog of stories does not push that section past its bound.
 *
 * `quote` is the story and is required. A success story with nothing said in it
 * is not a story, and the alternative -- letting the row exist and rendering an
 * empty card -- would be worse than not having the feature.
 *
 * @property int $id
 * @property string $name
 * @property string|null $title
 * @property string $quote
 * @property string|null $image_path
 * @property bool $featured
 * @property bool $active
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class SuccessStory extends Model
{
    /** @use HasFactory<SuccessStoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'title',
        'quote',
        'image_path',
        'featured',
        'active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The scope every public read uses: published, in display order.
     *
     * @param  Builder<SuccessStory>  $query
     * @return Builder<SuccessStory>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The homepage block: published AND flagged as featured.
     *
     * @param  Builder<SuccessStory>  $query
     * @return Builder<SuccessStory>
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('active', true)->where('featured', true)
            ->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The publicly reachable URL of this story's photo.
     *
     * Null when no photo was uploaded, in which case the story renders as text.
     */
    public function imageUrl(): ?string
    {
        if ($this->image_path === null || $this->image_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }
}

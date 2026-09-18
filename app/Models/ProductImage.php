<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Services\ProductMediaService;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One image in a product's gallery.
 *
 * The row is deliberately small: a path and an order. Gallery behaviour -- the
 * featured image, the limit, reordering, deletion -- lives in
 * {@see ProductMediaService}, the only thing
 * that writes these rows, and the first image (position zero) is the one a
 * listing shows.
 *
 * @property int $id
 * @property int $product_id
 * @property string $image_path
 * @property int $position
 * @property int|null $created_by
 */
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'image_path',
        'position',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The publicly reachable URL of this image.
     *
     * Gallery rows always store a path on the public disk, so this turns it
     * into the /storage/... URL a page can render straight into an img src.
     */
    public function url(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }
}

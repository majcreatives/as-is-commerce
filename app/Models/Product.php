<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Shared\Money\Money;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Concerns\GuardsMaterializedStock;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A physical product the platform owns and sells.
 *
 * Platform-owned inventory: there is no seller, vendor or merchant here, and
 * none should be added without a deliberate marketplace stage.
 *
 * PRICING. `buy_now_price_minor` is the product's own price in integer
 * pesewas. It is independent of every credit figure in the system -- credit
 * package prices, wallet balances, bid amounts -- and no code in this stage
 * derives one from another. A future Buy Now checkout will apply a discount
 * for credits a customer consumed bidding on this product's auction, at one
 * cedi per credit; that calculation belongs to that stage and does not exist
 * here.
 *
 * STOCK. `stock_on_hand` and `stock_reserved` are projections of the
 * inventory ledger, written only inside
 * {@see InventoryService}. Application code
 * cannot write them at all.
 *
 * @property int $id
 * @property string $sku
 * @property string $slug
 * @property string $name
 * @property string|null $short_description
 * @property string|null $description
 * @property int $category_id
 * @property int|null $brand_id
 * @property ProductCondition $condition
 * @property ProductStatus $status
 * @property int $buy_now_price_minor
 * @property string $currency
 * @property int $stock_on_hand
 * @property int $stock_reserved
 * @property string|null $image_path
 * @property Collection<int, ProductImage> $images
 * @property Carbon|null $published_at
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use GuardsMaterializedStock, HasFactory, LogsActivity;

    /**
     * Status and the stock columns are excluded on purpose. Status moves only
     * through the guarded lifecycle transition; stock moves only through the
     * inventory service. Neither is ever set from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sku',
        'slug',
        'name',
        'short_description',
        'description',
        'category_id',
        'brand_id',
        'condition',
        'buy_now_price_minor',
        'currency',
        'image_path',
        // A deliberate, explicit opt-in. Nothing is auctionable because it just
        // exists; an administrator has to mark it so. The initial stock-taking,
        // product transitions and a future platform-owned catalogue all share
        // this single flag.
        'auction_eligible',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => ProductCondition::class,
            'status' => ProductStatus::class,
            'buy_now_price_minor' => 'integer',
            'auction_eligible' => 'boolean',
            'stock_on_hand' => 'integer',
            'stock_reserved' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- Money

    /**
     * The Buy Now price, as an exact Money value.
     *
     * Never a float, and never derived from a credit figure.
     */
    public function buyNowPrice(): Money
    {
        return Money::fromMinor($this->buy_now_price_minor, $this->currency);
    }

    // ---------------------------------------------------------------- Stock

    /**
     * Stock a customer could actually buy right now.
     *
     * On hand less what is already spoken for. This is the figure any future
     * checkout must check, never `stock_on_hand` alone.
     */
    public function availableStock(): int
    {
        return max(0, $this->stock_on_hand - $this->stock_reserved);
    }

    public function isInStock(): bool
    {
        return $this->availableStock() > 0;
    }

    // --------------------------------------------------------------- State

    public function isPubliclyVisible(): bool
    {
        return $this->status->isPubliclyVisible();
    }

    /**
     * Whether this product could be sold, once checkout exists.
     *
     * Both conditions matter: an Active product with nothing available is not
     * purchasable, and neither is an archived one however much stock it has.
     */
    public function isPurchasable(): bool
    {
        return $this->status->isPurchasable() && $this->isInStock();
    }

    // -------------------------------------------------------- Relationships

    /**
     * Auctions that have run, or are running, on this product.
     *
     * Several are legitimate: a product with three units may have three
     * auctions, and an unsold one may be relisted. Anything asking "can a
     * customer get this right now" must filter to currently relevant statuses
     * rather than taking the newest -- a finished auction says nothing about
     * present availability, and its highest bid must never reach a listing.
     *
     * @return HasMany<Auction, $this>
     */
    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<InventoryTransaction, $this>
     */
    public function inventoryTransactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    /**
     * The gallery images for this product, featured first.
     *
     * Position zero is the featured image. Ordering always reads position
     * then id, so the front image is deterministic even while a transaction
     * is swapping positions around.
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * The image a listing should show: the featured gallery image, or the
     * product's own single-column picture before any gallery exists.
     *
     * Returns a URL a page can put straight into an <img src>.
     */
    public function image(): ?string
    {
        $featured = $this->images->first();

        if ($featured !== null) {
            return $featured->url();
        }

        return $this->image_path;
    }

    // --------------------------------------------------------------- Scopes

    /**
     * Products the public catalog may show.
     *
     * A single scope rather than a status check repeated at each call site,
     * so a draft or archived product cannot leak through a query someone
     * forgot to filter.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', ProductStatus::publiclyVisibleCases());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->whereColumn('stock_reserved', '<', 'stock_on_hand');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ------------------------------------------------------------ Audit log

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('product')
            ->logOnly([
                'sku', 'slug', 'name', 'category_id', 'brand_id',
                'condition', 'status', 'buy_now_price_minor', 'currency',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Product [{$this->sku}] was {$eventName}";
    }
}

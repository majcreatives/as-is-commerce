<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A manufacturer or marque.
 *
 * Optional on a product: plenty of goods -- an unbranded cable, a generic
 * accessory -- have no conventional brand, and forcing one would mean
 * inventing it.
 *
 * A product keeps its listing if its brand is removed, so that foreign key
 * nulls rather than restricting. Losing a brand label is recoverable; losing
 * the product is not.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property CatalogStatus $status
 */
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => CatalogStatus::class];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
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
            ->useLogName('brand')
            ->logOnly(['name', 'slug', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Brand [{$this->name}] was {$eventName}";
    }
}

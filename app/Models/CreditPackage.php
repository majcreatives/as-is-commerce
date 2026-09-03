<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use Database\Factories\CreditPackageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A fixed number of credits offered for a fixed price in GHS.
 *
 * The price says how much money buys how many credits, and nothing more. It
 * has no relationship to any product's Buy Now price, and credits never
 * convert back into money.
 *
 * This record is mutable configuration. A purchase never reads it at
 * fulfilment time -- it carries its own snapshot -- so repricing a package
 * cannot alter what an existing purchase is worth.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $credit_amount
 * @property int $price_minor
 * @property string $currency
 * @property bool $is_active
 * @property int $sort_order
 * @property array<string, mixed>|null $metadata
 */
class CreditPackage extends Model
{
    /** @use HasFactory<CreditPackageFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'credit_amount',
        'price_minor',
        'currency',
        'sort_order',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit_amount' => 'integer',
            'price_minor' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function price(): Money
    {
        return Money::fromMinor($this->price_minor, $this->currency);
    }

    /**
     * @return HasMany<CreditPurchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(CreditPurchase::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Packages as a customer should see them: available, in display order.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->active()->orderBy('sort_order')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('credit_package')
            ->logOnly([
                'name', 'slug', 'credit_amount', 'price_minor', 'currency', 'is_active', 'sort_order',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Credit package [{$this->name}] was {$eventName}";
    }
}

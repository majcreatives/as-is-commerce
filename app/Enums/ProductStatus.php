<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a product sits in its catalog lifecycle.
 *
 * This describes the *product*. It says nothing about whether an auction is
 * running on it -- those are separate concerns and the auction stage will
 * track its own state. A product being Active does not mean an auction is
 * live, and an auction ending does not archive a product.
 */
enum ProductStatus: string
{
    /** Being prepared. Never visible in the public catalog. */
    case Draft = 'draft';

    /** Listed publicly and, once checkout exists, purchasable. */
    case Active = 'active';

    /** Withdrawn from sale but not retired. Not purchasable. */
    case Inactive = 'inactive';

    /** Listed, but nothing available to sell right now. */
    case OutOfStock = 'out_of_stock';

    /** Retired. Never deleted, so its history stays readable. */
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::OutOfStock => 'Out of stock',
            self::Archived => 'Archived',
        };
    }

    /**
     * The statuses the public catalog may show.
     *
     * The single definition of public visibility. Both the query scope and
     * any relation filter read it from here, so the rule cannot drift into
     * two versions that disagree about what a customer can see.
     *
     * @return list<self>
     */
    public static function publiclyVisibleCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status->isPubliclyVisible(),
        ));
    }

    /**
     * Whether the public catalog may show this product.
     *
     * Out-of-stock products stay visible deliberately: hiding them loses the
     * page a customer may have bookmarked or been linked to, and "we have this,
     * just not right now" is more useful than a 404.
     */
    public function isPubliclyVisible(): bool
    {
        return match ($this) {
            self::Active, self::OutOfStock => true,
            self::Draft, self::Inactive, self::Archived => false,
        };
    }

    /**
     * Whether the product may be sold once checkout exists.
     *
     * Deliberately narrower than visibility: being listed and being sellable
     * are different questions, and conflating them is how an archived product
     * becomes purchasable through some alternate path.
     */
    public function isPurchasable(): bool
    {
        return $this === self::Active;
    }

    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Archived],
            // OutOfStock is reached by the inventory service, not by hand.
            self::Active => [self::Inactive, self::OutOfStock, self::Archived],
            self::Inactive => [self::Active, self::Archived],
            self::OutOfStock => [self::Active, self::Inactive, self::Archived],
            // Terminal. A retired product is restored by creating a new one,
            // so the record of what was sold under this listing stays intact.
            self::Archived => [],
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Draft => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Inactive => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::OutOfStock => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::Archived => 'bg-red-50 text-red-800 ring-red-200',
        };
    }
}

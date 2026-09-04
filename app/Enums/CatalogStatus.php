<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle shared by the catalog's organising records -- categories and
 * brands.
 *
 * Simpler than {@see ProductStatus} because these are taxonomy rather than
 * stock: there is nothing to be out of, and nothing to sell.
 */
enum CatalogStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    public function isPubliclyVisible(): bool
    {
        return $this === self::Active;
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Inactive => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Archived => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }
}

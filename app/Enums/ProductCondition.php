<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The physical condition of a product.
 *
 * An enum rather than a lookup table: the set is small, stable, and needs to
 * be reasoned about in code (a customer filtering by condition, a badge
 * colour). It matches how status, lot source and transaction types are
 * already modelled in this application.
 *
 * Condition is prominent in the customer interface on purpose. A marketplace
 * selling used and refurbished goods alongside new ones has to be
 * unambiguous about which is which.
 */
enum ProductCondition: string
{
    case New = 'new';
    case Used = 'used';
    case Refurbished = 'refurbished';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Used => 'Used',
            self::Refurbished => 'Refurbished',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::New => 'Unused, in its original packaging.',
            self::Used => 'Previously owned. Condition is described in the listing.',
            self::Refurbished => 'Restored and tested to working order.',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::New => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Used => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Refurbished => 'bg-brand-50 text-brand-800 ring-brand-200',
        };
    }
}

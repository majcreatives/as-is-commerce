<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an auction ruleset.
 *
 * Draft -> Active -> Archived, in that direction only. A ruleset that has
 * ever been active can never return to draft, because auctions may already
 * have been created from it and their history must stay explicable.
 */
enum RulesetStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether the ruleset's rule values may still be edited in place.
     *
     * Only drafts. Once active, a change must be made by creating a new
     * version so historical auctions remain reproducible.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ([$this, $target]) {
            [self::Draft, self::Active], [self::Draft, self::Archived],
            [self::Active, self::Archived] => true,
            default => false,
        };
    }

    /**
     * Tailwind classes for the status badge, kept with the enum so every
     * screen renders a given status identically.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Active => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Archived => 'bg-amber-50 text-amber-800 ring-amber-200',
        };
    }
}

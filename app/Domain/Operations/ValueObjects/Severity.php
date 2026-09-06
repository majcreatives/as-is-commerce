<?php

declare(strict_types=1);

namespace App\Domain\Operations\ValueObjects;

/**
 * How much attention something needs.
 *
 * Three levels, because a list where everything is urgent is a list nobody
 * triages. The distinction is about consequence rather than about noise:
 *
 *   Critical  Money or stock is in a state the platform cannot explain, or a
 *             customer has been told something that may not be true. Somebody
 *             should look today.
 *   Warning   Work is stuck. Nothing is wrong with the records, but somebody
 *             is waiting -- a customer for a refund decision, a package for a
 *             delivery address.
 *   Notice    Worth knowing, not worth interrupting anybody for.
 */
enum Severity: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Notice = 'notice';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Warning => 'Needs attention',
            self::Notice => 'Notice',
        };
    }

    /**
     * Most serious first, so a triage list reads top down.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Notice => 2,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Critical => 'bg-red-50 text-red-800 ring-red-200',
            self::Warning => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Notice => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}

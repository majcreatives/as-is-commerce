<?php

declare(strict_types=1);

namespace App\Enums;

use App\Domain\Shared\Money\Money;

/**
 * The storage type of an application setting.
 *
 * Settings are persisted as text; this enum tells the repository how to turn
 * that text back into a typed PHP value, so no caller ever writes its own
 * cast.
 */
enum SettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Money = 'money';

    public function label(): string
    {
        return match ($this) {
            self::String => 'Text',
            self::Integer => 'Whole number',
            self::Boolean => 'Yes / No',
            self::Money => 'Money',
        };
    }

    /**
     * Turn the stored text into its typed representation.
     */
    public function cast(?string $value, string $currency = 'GHS'): string|int|bool|Money|null
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String => $value,
            self::Integer => (int) $value,
            // Accepts the several shapes a boolean arrives in from forms and
            // seeds without ever treating the string "false" as true.
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            // Money is stored as integer minor units, so it round-trips
            // exactly and never passes through a float.
            self::Money => Money::fromMinor((int) $value, $currency),
        };
    }

    /**
     * Turn a typed value into the text that gets persisted.
     */
    public function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String => (string) $value,
            self::Integer => (string) (int) $value,
            self::Boolean => $value ? '1' : '0',
            self::Money => (string) ($value instanceof Money ? $value->minor : (int) $value),
        };
    }
}

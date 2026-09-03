<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a user account.
 *
 * Moderation tooling is a later stage; this enum only establishes the
 * vocabulary so status is never represented by a magic string.
 */
enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Banned => 'Banned',
        };
    }

    /**
     * Whether an account in this state may authenticate.
     */
    public function canAuthenticate(): bool
    {
        return match ($this) {
            self::Pending, self::Active => true,
            self::Suspended, self::Banned => false,
        };
    }
}

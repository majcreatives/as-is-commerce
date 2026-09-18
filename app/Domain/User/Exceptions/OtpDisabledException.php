<?php

declare(strict_types=1);

namespace App\Domain\User\Exceptions;

use RuntimeException;

/**
 * One-time codes have been switched off for the application.
 *
 * The flows fail explicitly rather than going quiet: a user who cannot be
 * verified should be told why, never left wondering whether a code is coming.
 */
final class OtpDisabledException extends RuntimeException
{
    public static function make(): self
    {
        return new self('One-time codes are currently unavailable. Please try again later.');
    }
}

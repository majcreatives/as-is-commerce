<?php

declare(strict_types=1);

namespace App\Domain\User\Exceptions;

use RuntimeException;

/**
 * A code submitted for verification was refused.
 *
 * The reason is internal. Flows surface a single, deliberately vague message
 * ("The code is invalid or has expired.") so an attacker cannot distinguish a
 * wrong digit from an expired code from a code that was never issued.
 */
final class InvalidOtpException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("The one-time code is invalid or has expired. ({$reason})");
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    public static function attemptsExhausted(): self
    {
        return new self('attempts_exhausted');
    }

    public static function mismatch(): self
    {
        return new self('mismatch');
    }
}

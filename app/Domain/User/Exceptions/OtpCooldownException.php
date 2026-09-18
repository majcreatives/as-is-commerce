<?php

declare(strict_types=1);

namespace App\Domain\User\Exceptions;

use RuntimeException;

/**
 * A code was requested again before the resend cooldown elapsed.
 *
 * Carries how long the caller should wait, so the UI can say something
 * truthful instead of a vague "try again later".
 */
final class OtpCooldownException extends RuntimeException
{
    public function __construct(
        public readonly int $secondsRemaining,
    ) {
        parent::__construct(
            "Please wait {$secondsRemaining} second".($secondsRemaining === 1 ? '' : 's')
            .' before requesting another code.',
        );
    }
}

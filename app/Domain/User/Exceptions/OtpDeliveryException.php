<?php

declare(strict_types=1);

namespace App\Domain\User\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The code could not be delivered to the account.
 *
 * Raised instead of pretending a code was sent. A verification flow that
 * looked successful while no code ever arrived would leave the user locked out
 * of the very action the code was meant to unlock.
 */
final class OtpDeliveryException extends RuntimeException
{
    public static function noDestination(): self
    {
        return new self(
            'This account has no email address to send a verification code to. '
            .'Add one on your profile and try again.',
        );
    }

    public static function deliveryFailed(?Throwable $previous = null): self
    {
        return new self(
            'The verification code could not be delivered. Please try again shortly.',
            previous: $previous,
        );
    }
}

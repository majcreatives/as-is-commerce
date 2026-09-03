<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * The provider could not be reached, or answered with something unusable.
 *
 * Deliberately carries no response body: provider errors can echo request
 * details, and this message may reach a log or a screen.
 */
final class PaymentGatewayError extends RuntimeException
{
    public static function unreachable(string $provider): self
    {
        return new self("Could not reach {$provider}. The payment was not affected.");
    }

    public static function rejected(string $provider, string $reason): self
    {
        return new self("{$provider} rejected the request: {$reason}");
    }

    public static function malformedResponse(string $provider): self
    {
        return new self("{$provider} returned a response this application could not read.");
    }

    public static function notConfigured(string $provider): self
    {
        return new self(
            "{$provider} is not configured. Set its credentials in the environment before taking payments."
        );
    }
}

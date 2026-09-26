<?php

declare(strict_types=1);

namespace App\Domain\User\Exceptions;

use RuntimeException;

/**
 * The SMS provider could not be reached, or answered with something unusable.
 *
 * Deliberately carries no request or response body: provider errors can echo
 * the message text, and that text contains a live one-time code. The failure
 * travels as an exception so the code issued for it can be rolled back.
 */
final class SmsGatewayError extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'The SMS provider is not configured. Set SMS_API_KEY and SMS_SENDER_ID '
            .'in the environment, and register the sender ID with the provider.'
        );
    }

    public static function unreachable(string $reason): self
    {
        return new self("Could not reach the SMS provider: {$reason}");
    }

    public static function rejected(string $reason): self
    {
        return new self("The SMS provider rejected the request: {$reason}");
    }

    public static function malformedResponse(): self
    {
        return new self('The SMS provider returned a response this application could not read.');
    }
}

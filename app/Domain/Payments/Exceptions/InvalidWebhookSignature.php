<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * A webhook arrived without a signature this application could verify.
 *
 * Unsigned or wrongly signed events are discarded without being stored or
 * acted on: anyone can POST to a public endpoint, and the signature is the
 * only thing distinguishing the provider from an attacker.
 */
final class InvalidWebhookSignature extends RuntimeException
{
    public static function for(string $provider): self
    {
        return new self("The {$provider} webhook signature could not be verified.");
    }
}

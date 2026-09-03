<?php

declare(strict_types=1);

namespace App\Domain\Payments\ValueObjects;

/**
 * What a provider hands back when a transaction is opened.
 *
 * Provider-neutral: the adapter parses whatever shape its API returns into
 * this, so nothing downstream reads a Paystack-specific response body.
 */
final readonly class InitializedTransaction
{
    public function __construct(
        public string $reference,
        public string $authorizationUrl,
        public ?string $accessCode = null,
    ) {}
}

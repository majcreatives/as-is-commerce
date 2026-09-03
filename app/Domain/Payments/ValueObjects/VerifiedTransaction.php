<?php

declare(strict_types=1);

namespace App\Domain\Payments\ValueObjects;

use DateTimeImmutable;

/**
 * The provider's own account of a transaction, fetched server-to-server.
 *
 * This is the only evidence of payment the platform accepts. A browser
 * returning from a payment page proves nothing -- the customer may have
 * abandoned the payment, or edited the URL -- so credits are granted on the
 * strength of this object and nothing else.
 *
 * Amounts are integer minor units, as the provider reports them.
 */
final readonly class VerifiedTransaction
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $reference,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?int $providerTransactionId = null,
        public ?string $channel = null,
        public ?DateTimeImmutable $paidAt = null,
        public array $raw = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
}

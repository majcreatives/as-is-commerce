<?php

declare(strict_types=1);

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Payments\ValueObjects\InitializedTransaction;
use App\Domain\Payments\ValueObjects\VerifiedTransaction;
use App\Domain\Shared\Money\Money;
use App\Models\User;

/**
 * The boundary between the platform and whoever moves the money.
 *
 * Everything provider-specific -- endpoints, request shapes, response parsing,
 * signature schemes -- lives behind this. The credit and wallet domains depend
 * on the interface, so replacing Paystack later is an adapter swap rather than
 * a rewrite of the accounting.
 */
interface PaymentGateway
{
    /**
     * Open a transaction with the provider and get somewhere to send the payer.
     *
     * The amount is passed as a Money value taken from the server-side
     * purchase snapshot. No caller may supply an amount originating from the
     * browser.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws PaymentGatewayError
     */
    public function initializeTransaction(
        User $user,
        Money $amount,
        string $reference,
        string $callbackUrl,
        array $metadata = [],
    ): InitializedTransaction;

    /**
     * Ask the provider what actually happened to a transaction.
     *
     * @throws PaymentGatewayError
     */
    public function verifyTransaction(string $reference): VerifiedTransaction;

    /**
     * Whether a webhook payload genuinely came from the provider.
     *
     * Takes the raw request body: re-encoding a decoded payload would change
     * the bytes and invalidate the signature.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool;

    /**
     * A stable identifier for an event, so a repeat delivery is recognisable.
     *
     * @param  array<string, mixed>  $payload
     */
    public function eventIdentifier(array $payload): string;
}

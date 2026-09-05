<?php

declare(strict_types=1);

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Payments\ValueObjects\InitializedTransaction;
use App\Domain\Payments\ValueObjects\ProviderRefund;
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
     * Ask the provider to return money against a transaction it settled.
     *
     * The amount comes from a server-side calculation -- the payment's own
     * frozen amount, less what has already been returned against it -- and
     * never from a browser. Omitting it asks for the whole transaction.
     *
     * ACCEPTANCE IS NOT SUCCESS. What comes back says where the provider has
     * got to, which for Paystack is usually "not finished". A caller records
     * that state honestly and asks again later; it never reads an accepted
     * request as money returned.
     *
     * @throws PaymentGatewayError
     */
    public function refundTransaction(
        string $reference,
        ?Money $amount = null,
        ?string $reason = null,
    ): ProviderRefund;

    /**
     * Ask the provider what became of a refund it accepted earlier.
     *
     * The authoritative answer for an asynchronous refund, and the reason a
     * refund can be marked succeeded at all.
     *
     * @throws PaymentGatewayError
     */
    public function fetchRefund(string $providerReference): ProviderRefund;

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

<?php

declare(strict_types=1);

namespace App\Domain\User\Contracts;

use App\Domain\User\Exceptions\SmsGatewayError;

/**
 * The boundary between the platform and whoever carries a text message.
 *
 * Everything provider-specific -- endpoints, authentication, request shapes,
 * response parsing -- lives behind this, so the user domain can ask for a
 * message without ever naming Arkesel. Swapping to a different Ghanaian
 * provider is a new adapter and one binding line, not a change to the code
 * flows that call it.
 *
 * Kept deliberately smaller than the payment gateway: a text message carries
 * no money, no signature to verify and no provider-side state to reconcile.
 */
interface SmsGateway
{
    /**
     * Hand one message to the provider for delivery to a phone number.
     *
     * Synchronous, and it must mean *accepted for delivery* rather than
     * "request received". An undelivered message has to throw so the
     * verification code issued for it is rolled back instead of being left in
     * the database where nobody can receive it and the customer is locked out
     * of the very action the code was meant to unlock.
     *
     * @param  string  $e164Phone  Canonical E.164 destination, e.g. +233244123456.
     * @param  string  $message  The text to deliver. Never log this: it carries a code.
     *
     * @throws SmsGatewayError
     */
    public function send(string $e164Phone, string $message): void;
}

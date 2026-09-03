<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use DomainException;

/**
 * The provider's account of a transaction did not match our own record.
 *
 * Every one of these is a refusal to grant credits. A mismatch means either a
 * bug or an attempt to manipulate a payment, and both are reasons to stop
 * rather than to guess at what was intended.
 */
final class PaymentVerificationFailed extends DomainException
{
    public static function notSuccessful(string $status): self
    {
        return new self("The payment did not succeed; the provider reports status [{$status}].");
    }

    public static function referenceMismatch(string $expected, string $actual): self
    {
        return new self("Reference mismatch: expected [{$expected}], provider returned [{$actual}].");
    }

    public static function amountMismatch(int $expectedMinor, int $actualMinor): self
    {
        return new self(
            "Amount mismatch: the purchase records {$expectedMinor} but the provider verified {$actualMinor}."
        );
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self("Currency mismatch: expected [{$expected}], provider returned [{$actual}].");
    }

    public static function unknownPurchase(string $reference): self
    {
        return new self("No credit purchase matches reference [{$reference}].");
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}

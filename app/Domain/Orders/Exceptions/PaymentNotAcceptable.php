<?php

declare(strict_types=1);

namespace App\Domain\Orders\Exceptions;

use DomainException;

/**
 * A payment that will not be initialized or will not be honoured.
 *
 * Covers both ends: refusing to open a payment against an order that cannot
 * take one, and refusing to fulfil against a provider answer that does not
 * match what was asked for. The second is never "close enough" -- a mismatch
 * means either a bug or an attempt to manipulate a payment, and both are
 * reasons to stop.
 */
final class PaymentNotAcceptable extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function orderNotPayable(string $status): self
    {
        return new self("This order is not awaiting payment ({$status}).");
    }

    public static function expired(): self
    {
        return new self('This checkout has expired. Start a new one.');
    }

    public static function amountMismatch(int $expected, int $received): self
    {
        return new self(
            "The provider reports {$received} minor units against the {$expected} this payment "
            .'was opened for.'
        );
    }

    public static function currencyMismatch(string $expected, string $received): self
    {
        return new self("The provider reports {$received} against the expected {$expected}.");
    }

    public static function referenceMismatch(string $expected, string $received): self
    {
        return new self("The provider reports reference [{$received}] against [{$expected}].");
    }

    public static function notSuccessful(string $status): self
    {
        return new self("The provider reports this payment as [{$status}], not success.");
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Refunds\Exceptions;

use App\Domain\Shared\Money\Money;
use DomainException;

/**
 * A refund that must not happen.
 *
 * Every message here is safe to show a member of staff and says which rule
 * stopped it, so somebody reading it knows whether they have hit a policy or a
 * bug. None of them carries a credential, a payload or another customer's
 * detail.
 */
final class RefundNotAllowed extends DomainException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    public static function paymentNotSuccessful(): self
    {
        return new self(
            'This payment never succeeded, so there is nothing to give back. '
            .'A refund can only return money the platform actually received.'
        );
    }

    public static function nothingRefundable(): self
    {
        return new self(
            'This payment has already been refunded in full, or a refund for the '
            .'whole of it is already in progress.'
        );
    }

    public static function exceedsRefundable(Money $requested, Money $refundable): self
    {
        return new self(
            "A refund of {$requested->format()} was requested but only {$refundable->format()} "
            .'may still be returned against this payment.'
        );
    }

    public static function notPositive(): self
    {
        return new self('A refund must be for a positive amount.');
    }

    public static function alreadyInProgress(): self
    {
        return new self(
            'A refund against this payment is already in progress. Wait for it to '
            .'settle rather than starting a second one.'
        );
    }

    public static function orderNotRecoverable(string $status): self
    {
        return new self(
            "This order is [{$status}] and its fulfilment is not blocked, so it is not a "
            .'recovery case. Refunds exist to return money the platform could not deliver '
            .'against.'
        );
    }

    public static function alreadyDelivered(): self
    {
        return new self(
            'This order was delivered to the customer. Refunding a delivered order is a '
            .'return, which this platform does not yet handle.'
        );
    }

    public static function notPending(string $status): self
    {
        return new self(
            "This refund is [{$status}] and is not waiting to be sent to the provider."
        );
    }
}

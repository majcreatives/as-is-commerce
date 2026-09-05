<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Exceptions;

use App\Enums\DeliveryStatus;
use DomainException;

/**
 * A delivery move that must not happen.
 *
 * Every message here is safe to show a member of staff and says which rule
 * stopped it, so somebody reading it knows whether they have hit a policy or a
 * bug. None carries a credential, a payload or another customer's detail.
 */
final class DeliveryNotAllowed extends DomainException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    public static function between(DeliveryStatus $from, DeliveryStatus $to): self
    {
        return new self(
            "A delivery cannot go from [{$from->value}] to [{$to->value}]."
        );
    }

    public static function noAddress(): self
    {
        return new self(
            'This delivery has no address yet, so there is nowhere to send it. '
            .'The customer needs to supply one before the package can be prepared.'
        );
    }

    public static function addressFrozen(): self
    {
        return new self(
            'This package is already being handled, so its address is fixed. '
            .'A delivery records where something actually went.'
        );
    }

    public static function orderNotFulfillable(string $status): self
    {
        return new self(
            "This order is [{$status}] and cannot be fulfilled. Only a paid order "
            .'the platform can actually deliver against enters fulfilment.'
        );
    }

    public static function orderBlocked(): self
    {
        return new self(
            'This order is marked as blocked: a payment succeeded but nothing could '
            .'be delivered against it. It needs resolving before any package moves.'
        );
    }

    public static function alreadyExists(string $reference): self
    {
        return new self(
            "This order already has delivery [{$reference}]. One order, one package."
        );
    }

    public static function notOwned(): self
    {
        return new self('That address does not belong to this customer.');
    }
}

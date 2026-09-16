<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

/**
 * A cart line that will not be written, or a cart that cannot be placed.
 *
 * Refusals before anything financial moves: a cart is intent, and nothing that
 * happens to it touches a ledger or a reservation. The messages are written to
 * be shown to a customer, because "the cart was not updated" tells someone
 * about to spend money nothing they can act on.
 */
final class InvalidCart extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}

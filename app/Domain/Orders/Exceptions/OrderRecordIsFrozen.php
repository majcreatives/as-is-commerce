<?php

declare(strict_types=1);

namespace App\Domain\Orders\Exceptions;

use DomainException;

/**
 * An attempt to change the commercial facts of a paid order.
 *
 * Once a payment is verified, the amounts on an order describe a transaction
 * that actually happened. Editing them would rewrite history and leave the
 * money received unaccounted for.
 *
 * A correction is a separate, explicit financial act with its own records --
 * not a change to the original transaction. Raised by the model guard; a
 * database trigger refuses the same writes for any path that does not go
 * through Eloquent.
 */
final class OrderRecordIsFrozen extends DomainException
{
    public static function for(string $column): self
    {
        return new self(
            "[{$column}] cannot change once an order has been paid: it is a historical record."
        );
    }
}

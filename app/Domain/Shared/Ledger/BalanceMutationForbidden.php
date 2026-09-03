<?php

declare(strict_types=1);

namespace App\Domain\Shared\Ledger;

use RuntimeException;

/**
 * Raised when code tries to write a wallet balance directly.
 *
 * The balance is derived from the ledger. Changing it means posting a
 * transaction, not assigning to a column.
 */
final class BalanceMutationForbidden extends RuntimeException
{
    public static function for(string $model, string $column): self
    {
        return new self(
            "Refusing to write [{$model}::\${$column}] directly. The ledger is the "
            .'authoritative record: post a transaction through the ledger service '
            .'instead of assigning to the balance.'
        );
    }
}

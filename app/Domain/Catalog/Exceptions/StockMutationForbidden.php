<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use RuntimeException;

/**
 * Raised when code tries to write a stock column directly.
 *
 * Stock on hand and stock reserved are projections of the inventory ledger.
 * Changing them means posting a movement, not assigning to a column -- the
 * same rule the credit and cash wallets follow, for the same reason: a second
 * source of truth silently drifts from the first.
 */
final class StockMutationForbidden extends RuntimeException
{
    public static function for(string $column): self
    {
        return new self(
            "Refusing to write [Product::\${$column}] directly. Stock is derived from the "
            .'inventory ledger: post a movement through InventoryService instead.'
        );
    }
}

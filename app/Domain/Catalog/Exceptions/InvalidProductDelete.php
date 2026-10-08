<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

/**
 * A product deletion that would destroy history.
 *
 * Deleting a product is a permanent removal, so it is refused whenever the row
 * is referenced by something that must survive it -- an auction, an order line,
 * a stock movement or a customer's cart. The status lifecycle's `archive` is
 * the alternative that keeps that history readable.
 */
final class InvalidProductDelete extends DomainException
{
    public static function referenced(): self
    {
        return new self(
            'This product cannot be deleted because it is part of auction, order or inventory history, or is in a customer\'s cart. Archive it instead.'
        );
    }
}

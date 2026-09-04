<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Enums\ProductStatus;
use DomainException;

/**
 * A product lifecycle move that is not permitted.
 *
 * Refused rather than absorbed: a status machine that quietly accepts any
 * move is how an archived listing becomes purchasable again.
 */
final class InvalidProductTransition extends DomainException
{
    public static function between(ProductStatus $from, ProductStatus $to): self
    {
        return new self("A product cannot move from {$from->label()} to {$to->label()}.");
    }

    public static function notEditable(ProductStatus $status): self
    {
        return new self(
            "A {$status->label()} product cannot be edited. Its listing is the record of what was sold under it."
        );
    }
}

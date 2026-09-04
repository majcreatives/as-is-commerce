<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use DomainException;

/**
 * A stock movement that cannot be applied.
 *
 * Every one of these is a refusal to change stock. Overselling and negative
 * inventory are the failures this prevents, and both are worth stopping
 * loudly rather than absorbing.
 */
final class InvalidStockMovement extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function wouldGoNegative(int $current, int $delta): self
    {
        return new self(
            "Refusing the movement: stock is {$current} and this would change it by {$delta}, "
            .'which would leave it negative.'
        );
    }

    public static function insufficientAvailable(int $requested, int $available): self
    {
        return new self(
            "Cannot reserve {$requested}: only {$available} available."
        );
    }

    public static function wouldReleaseMoreThanReserved(int $requested, int $reserved): self
    {
        return new self(
            "Cannot release {$requested}: only {$reserved} reserved."
        );
    }
}

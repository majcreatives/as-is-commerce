<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use DomainException;

/**
 * An attempt to change what an auction was created under.
 *
 * The snapshot, the settlement amount, the product and the currency are fixed
 * once an auction leaves draft. Changing any of them would rewrite the terms
 * people are bidding under, or make a closed auction unexplainable.
 *
 * Raised by the model guard; a database trigger refuses the same writes for
 * any path that does not go through Eloquent.
 */
final class AuctionConfigurationIsFrozen extends DomainException
{
    public static function for(string $column): self
    {
        return new self(
            "[{$column}] is frozen once an auction leaves draft: create a new auction instead."
        );
    }
}

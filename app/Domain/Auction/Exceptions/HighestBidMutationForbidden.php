<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use DomainException;

/**
 * An attempt to write the highest-bid projection from outside the resolver.
 *
 * The bid records decide who is highest. These columns are a cache of that
 * answer, and a hand-written value would be a second, unaccountable source of
 * truth for the one number that determines who wins.
 */
final class HighestBidMutationForbidden extends DomainException
{
    public static function for(string $column): self
    {
        return new self(
            "[{$column}] is a projection of the bid records and cannot be written directly. "
            .'Record a bid, or rebuild the projection from the bids.'
        );
    }
}

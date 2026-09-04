<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use DomainException;

/**
 * Buy Now cannot complete on this auction.
 *
 * The important case is the race: a second Buy Now arriving after a first one
 * succeeded gets this, because the auction row it locked already says the
 * product is gone. That refusal is the mechanism by which only the first
 * successfully completed Buy Now wins, and it is why the check happens under
 * a lock rather than against a status read a moment earlier.
 */
final class BuyNowUnavailable extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function auctionEnded(): self
    {
        return new self('This auction has already ended, so it can no longer be bought outright.');
    }

    public static function alreadyBought(): self
    {
        return new self('This product has already been bought outright by someone else.');
    }

    public static function disabledByRules(): self
    {
        return new self('Buy Now is not available on this auction.');
    }
}

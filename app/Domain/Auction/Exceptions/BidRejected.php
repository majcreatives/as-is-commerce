<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use DomainException;

/**
 * A bid that will not be recorded.
 *
 * Every one of these means nothing happened: no row was written and no
 * credits moved, because validation runs inside the same transaction as the
 * consumption and a refusal rolls all of it back.
 *
 * The messages are written to be shown to the bidder. They say what the rule
 * is and what would satisfy it, because "invalid bid" tells someone who just
 * tried to spend credits nothing they can act on.
 */
final class BidRejected extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function notOpen(string $status): self
    {
        return new self("This auction is not accepting bids ({$status}).");
    }

    public static function notStarted(): self
    {
        return new self('This auction has not started yet.');
    }

    public static function alreadyEnded(): self
    {
        return new self('This auction has already ended.');
    }

    public static function amountNotPositive(int $amount): self
    {
        return new self("A bid must commit at least 1 credit; {$amount} was given.");
    }

    public static function belowMinimum(int $amount, int $minimum): self
    {
        return new self(
            "A bid of {$amount} credits is below this auction's minimum of {$minimum} credits."
        );
    }

    public static function belowIncrement(int $amount, int $required, int $highest): self
    {
        return new self(
            "A bid of {$amount} credits does not clear the standing highest bid of {$highest} "
            ."credits: the next valid bid is {$required} credits."
        );
    }

    public static function increasesOwnBid(int $highest): self
    {
        return new self(
            "This auction does not allow raising your own bid, and yours of {$highest} credits "
            .'is already the highest.'
        );
    }

    public static function tooSoon(int $waitMs): self
    {
        return new self("Bids are limited on this auction. Try again in {$waitMs} ms.");
    }
}

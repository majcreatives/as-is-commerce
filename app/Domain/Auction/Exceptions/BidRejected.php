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

    /**
     * Somebody who holds the lead under the cumulative model tried to bid.
     *
     * A leader has no bid to place until somebody overtakes them. Said plainly,
     * because the usual way to reach this is a stale page or a double click,
     * and "invalid bid" would leave them wondering what they did wrong.
     */
    public static function leaderCannotBid(int $standing): self
    {
        return new self(
            "You already hold the lead with {$standing} credits. "
            .'You can bid again once somebody overtakes you.'
        );
    }

    /**
     * The opening bid of a cumulative auction is not the minimum.
     */
    public static function notTheOpeningBid(int $amount, int $opening): self
    {
        return new self(
            "The opening bid on this auction is exactly {$opening} credits; {$amount} was given."
        );
    }

    /**
     * A cumulative bid is not the one that would take the lead.
     *
     * The usual cause is somebody bidding while this bidder was looking at the
     * page: the amount they were shown was right when it was shown. The message
     * gives the figure that is right NOW, so the retry is one click, and it is
     * never substituted silently -- that would spend more credits than the
     * bidder agreed to.
     */
    public static function notTheCatchUpBid(int $amount, int $expected, int $leaderTotal, int $yourTotal): self
    {
        return new self(
            "To take the lead you need to add exactly {$expected} credits "
            ."(the leader has {$leaderTotal} and you have {$yourTotal}); {$amount} was given."
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

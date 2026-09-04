<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use App\Enums\AuctionStatus;
use DomainException;

/**
 * A lifecycle move the state machine does not permit.
 *
 * Always a refusal, never a warning. A state machine that quietly allows an
 * illegal move is how an auction gets settled without a winner, or reopened
 * after it closed and its credits were spent.
 */
final class InvalidAuctionTransition extends DomainException
{
    public static function between(AuctionStatus $from, AuctionStatus $to): self
    {
        return new self(
            "An auction cannot move from [{$from->value}] to [{$to->value}]."
        );
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}

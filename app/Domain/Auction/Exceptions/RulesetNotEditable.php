<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use App\Enums\RulesetStatus;
use DomainException;

/**
 * Raised when a caller tries to change a ruleset that auctions may already
 * have been created from.
 */
final class RulesetNotEditable extends DomainException
{
    public static function status(RulesetStatus $status): self
    {
        return new self(
            "A {$status->label()} ruleset cannot be edited. Create a new version instead."
        );
    }

    public static function transition(RulesetStatus $from, RulesetStatus $to): self
    {
        return new self(
            "A ruleset cannot move from {$from->label()} to {$to->label()}."
        );
    }
}

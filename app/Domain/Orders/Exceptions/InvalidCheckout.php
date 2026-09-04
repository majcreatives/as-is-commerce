<?php

declare(strict_types=1);

namespace App\Domain\Orders\Exceptions;

use DomainException;

/**
 * A checkout that will not be created, or a total that does not add up.
 *
 * Every one of these is a refusal before any money is asked for. The messages
 * are written to be shown to a customer, because "checkout failed" tells
 * someone about to spend money nothing they can act on.
 */
final class InvalidCheckout extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function notPurchasable(string $name): self
    {
        return new self("[{$name}] is not available to buy right now.");
    }

    public static function alreadyOpen(string $orderNumber): self
    {
        return new self(
            "You already have an open checkout for this ({$orderNumber}). Finish or cancel it first."
        );
    }

    public static function notTheWinner(): self
    {
        return new self('Only the auction winner can settle this auction.');
    }

    public static function auctionNotAwaitingSettlement(string $status): self
    {
        return new self("This auction is not awaiting settlement ({$status}).");
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Auction\Exceptions;

use DomainException;

final class InvalidAuctionRules extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function unsupportedSnapshotVersion(int $version): self
    {
        return new self(
            "Auction rules snapshot version [{$version}] is not supported by this application version."
        );
    }
}

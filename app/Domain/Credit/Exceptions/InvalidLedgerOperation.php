<?php

declare(strict_types=1);

namespace App\Domain\Credit\Exceptions;

use DomainException;

final class InvalidLedgerOperation extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}

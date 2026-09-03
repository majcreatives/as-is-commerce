<?php

declare(strict_types=1);

namespace App\Domain\Credit\Exceptions;

use DomainException;

final class InsufficientCredits extends DomainException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            "Insufficient credits: {$requested} requested, {$available} available."
        );
    }
}

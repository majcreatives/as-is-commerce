<?php

declare(strict_types=1);

namespace App\Domain\Cash\Exceptions;

use App\Domain\Shared\Money\Money;
use DomainException;

final class InsufficientFunds extends DomainException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $available,
        public readonly string $currency = 'GHS',
    ) {
        parent::__construct(sprintf(
            'Insufficient funds: %s requested, %s available.',
            Money::fromMinor($requested, $currency)->format(),
            Money::fromMinor($available, $currency)->format(),
        ));
    }
}

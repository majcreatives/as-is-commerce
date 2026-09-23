<?php

declare(strict_types=1);

namespace App\Domain\Credit\Exceptions;

use App\Domain\Credit\ValueObjects\CreditAmount;
use DomainException;

final class InsufficientCredits extends DomainException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            'Insufficient credits: '
            .CreditAmount::fromSubcredits($requested)->format()
            .' requested, '.CreditAmount::fromSubcredits($available)->format().' available.'
        );
    }
}

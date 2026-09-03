<?php

declare(strict_types=1);

namespace App\Domain\Credit\ValueObjects;

use App\Models\CreditLot;

/**
 * A planned draw of a given quantity from a given lot.
 *
 * Produced by the allocator before anything is written, so the full plan can
 * be validated as a whole and then applied atomically.
 */
final readonly class CreditAllocation
{
    public function __construct(
        public CreditLot $lot,
        public int $amount,
    ) {}
}

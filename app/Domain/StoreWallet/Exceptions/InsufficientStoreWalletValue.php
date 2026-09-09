<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\Exceptions;

use App\Domain\Shared\Money\Money;
use DomainException;

/**
 * A Store Wallet debit that would take the balance below zero.
 *
 * Always thrown from inside the ledger service, with the wallet row locked, so
 * the balance quoted here is the real one at the moment of refusal rather than
 * a figure that had already moved on. Nothing has been written when this is
 * raised: the caller's transaction rolls back whole.
 */
final class InsufficientStoreWalletValue extends DomainException
{
    public function __construct(
        public readonly Money $requested,
        public readonly Money $available,
    ) {
        parent::__construct(
            "This needs {$requested->format()} of Store Wallet value and only "
            ."{$available->format()} is available."
        );
    }

    public static function for(Money $requested, Money $available): self
    {
        return new self($requested, $available);
    }
}

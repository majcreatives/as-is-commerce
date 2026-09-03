<?php

declare(strict_types=1);

namespace App\Domain\Shared\Ledger;

use RuntimeException;

/**
 * Raised when code tries to rewrite financial history.
 *
 * Corrections are made by posting a compensating entry, so that both what was
 * originally recorded and the correction remain visible. Editing the original
 * would destroy the only evidence of what the system believed at the time.
 */
final class FinancialHistoryIsImmutable extends RuntimeException
{
    public static function cannotUpdate(string $model, mixed $key): self
    {
        return new self(
            "Refusing to update [{$model}#{$key}]. Financial records are append-only; "
            .'post a compensating entry instead.'
        );
    }

    public static function cannotDelete(string $model, mixed $key): self
    {
        return new self(
            "Refusing to delete [{$model}#{$key}]. Financial records are append-only; "
            .'post a compensating entry instead.'
        );
    }
}

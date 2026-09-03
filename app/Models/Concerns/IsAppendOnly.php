<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Shared\Ledger\FinancialHistoryIsImmutable;

/**
 * Refuses updates and deletes on a financial record.
 *
 * The database enforces this too, with triggers, and that is the real
 * guarantee. This trait exists so the failure surfaces as a clear domain
 * exception at the point of the mistake rather than as a raw SQL error, and
 * so the rule is visible in the model to anyone reading it.
 */
trait IsAppendOnly
{
    protected static function bootIsAppendOnly(): void
    {
        static::updating(function (self $model): void {
            throw FinancialHistoryIsImmutable::cannotUpdate(static::class, $model->getKey());
        });

        static::deleting(function (self $model): void {
            throw FinancialHistoryIsImmutable::cannotDelete(static::class, $model->getKey());
        });
    }
}

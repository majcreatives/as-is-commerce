<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Shared\Ledger\BalanceMutationForbidden;
use Closure;

/**
 * Makes a wallet's materialized balance unwritable from ordinary code.
 *
 * The ledger is authoritative; the balance column is a cached projection of
 * it. Letting a controller write `$wallet->balance += 100` would create a
 * second source of truth that silently drifts from the ledger, which is the
 * failure this whole architecture exists to prevent.
 *
 * So the column is not fillable, and a save that changes it is rejected
 * unless it happens inside a ledger service, which opens a short window
 * around its own write. The window is process-local and closes in a finally
 * block, so a thrown exception cannot leave it open.
 */
trait GuardsMaterializedBalance
{
    private static bool $balanceWritesPermitted = false;

    /**
     * Run a callback with balance writes permitted.
     *
     * Only ledger services should call this, and only around a write that is
     * accompanied by the ledger row justifying it.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function permittingBalanceWrites(Closure $callback): mixed
    {
        $previous = self::$balanceWritesPermitted;
        self::$balanceWritesPermitted = true;

        try {
            return $callback();
        } finally {
            self::$balanceWritesPermitted = $previous;
        }
    }

    protected static function bootGuardsMaterializedBalance(): void
    {
        static::saving(function (self $wallet): void {
            $column = $wallet->balanceColumn();

            if ($wallet->isDirty($column) && ! self::$balanceWritesPermitted) {
                throw BalanceMutationForbidden::for(static::class, $column);
            }
        });
    }

    /**
     * The name of the materialized balance column on this wallet.
     */
    abstract public function balanceColumn(): string;
}

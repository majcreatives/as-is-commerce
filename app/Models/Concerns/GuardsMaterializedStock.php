<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Catalog\Exceptions\StockMutationForbidden;
use Closure;

/**
 * Makes a product's stock columns unwritable from ordinary code.
 *
 * The inventory ledger is authoritative; `stock_on_hand` and `stock_reserved`
 * are cached projections of it. Letting a controller write
 * `$product->stock_on_hand += 5` would create a second source of truth with
 * no audit trail behind it, which is exactly what this stage exists to
 * prevent.
 *
 * An update that changes one is rejected unless it happens inside the
 * inventory service, which opens a short process-local window around its own
 * write. The window closes in a finally block, so a thrown exception cannot
 * leave it open.
 *
 * The guard covers updates rather than inserts, because updates are where
 * drift happens: a new product starts at zero, since neither column is
 * fillable and nothing in the request path can set them. Stock only ever
 * arrives through a recorded movement.
 *
 * This mirrors {@see GuardsMaterializedBalance} on the wallets, deliberately:
 * same problem, same shape of answer.
 */
trait GuardsMaterializedStock
{
    /** @var list<string> */
    private static array $stockColumns = ['stock_on_hand', 'stock_reserved'];

    private static bool $stockWritesPermitted = false;

    /**
     * Run a callback with stock writes permitted.
     *
     * Only the inventory service should call this, and only around a write
     * accompanied by the ledger row that accounts for it.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function permittingStockWrites(Closure $callback): mixed
    {
        $previous = self::$stockWritesPermitted;
        self::$stockWritesPermitted = true;

        try {
            return $callback();
        } finally {
            self::$stockWritesPermitted = $previous;
        }
    }

    protected static function bootGuardsMaterializedStock(): void
    {
        static::updating(function (self $model): void {
            if (self::$stockWritesPermitted) {
                return;
            }

            foreach (self::$stockColumns as $column) {
                if ($model->isDirty($column)) {
                    throw StockMutationForbidden::for($column);
                }
            }
        });
    }
}

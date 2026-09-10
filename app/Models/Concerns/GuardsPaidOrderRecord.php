<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Orders\Exceptions\OrderRecordIsFrozen;
use App\Enums\OrderStatus;

/**
 * Freezes an order's commercial figures the moment money is verified.
 *
 * Before payment an order may still be corrected: nobody has paid anything and
 * a wrong price is just a wrong price. Afterwards the amounts describe a
 * transaction that happened, and changing them would leave money received
 * unaccounted for and a customer's receipt disagreeing with our records.
 *
 * Lifecycle columns stay writable throughout -- status, timestamps, the
 * fulfilment-blocked reason. It is the commercial facts that freeze, not the
 * order.
 *
 * Unlike the projection guards there is no permitting window: nothing in the
 * application may change these after payment, the domain services included. A
 * database trigger refuses the same writes for any path that does not go
 * through Eloquent at all.
 *
 * This mirrors {@see GuardsFrozenAuctionConfiguration}, deliberately: same
 * problem -- a historical record that must stay explicable -- same answer.
 */
trait GuardsPaidOrderRecord
{
    /** @var list<string> */
    private static array $frozenOrderColumns = [
        'subtotal_minor',
        'discount_minor',
        'discount_credits',
        'delivery_minor',
        'tax_minor',
        'total_minor',
        'store_wallet_applied_minor',
        'payable_minor',
        'currency',
        'pricing_snapshot',
        'source',
        'auction_id',
        'winning_bid_id',
        'user_id',
    ];

    protected static function bootGuardsPaidOrderRecord(): void
    {
        static::updating(function (self $model): void {
            // getOriginal, not the current value: an update that also changes
            // the status must be judged by the state the order was actually
            // in when the write began. Otherwise the write that marks an order
            // paid could smuggle a new total in alongside.
            $original = $model->getOriginal('status');

            $was = $original instanceof OrderStatus
                ? $original
                : OrderStatus::tryFrom((string) $original);

            if ($was === null || ! $was->isCommerciallyFrozen()) {
                return;
            }

            foreach (self::$frozenOrderColumns as $column) {
                if ($model->isDirty($column)) {
                    throw OrderRecordIsFrozen::for($column);
                }
            }
        });
    }
}

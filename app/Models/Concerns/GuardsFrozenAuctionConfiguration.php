<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Auction\Exceptions\AuctionConfigurationIsFrozen;
use App\Enums\AuctionStatus;

/**
 * Freezes an auction's terms once it is published.
 *
 * An auction is a historical instance, not a live view of configuration.
 * People commit real credits against the terms it was created with, so those
 * terms cannot move underneath them -- and months later the record still has
 * to explain how the auction behaved.
 *
 * Draft is the one state in which the configuration may still change, because
 * a draft is not visible to anyone and has taken no bids.
 *
 * Unlike the projection guards there is no permitting window: nothing in the
 * application is allowed to change these after publication, including the
 * domain services. A database trigger refuses the same writes for any path
 * that does not go through Eloquent at all.
 */
trait GuardsFrozenAuctionConfiguration
{
    /** @var list<string> */
    private static array $frozenColumns = [
        'rules_snapshot',
        'snapshot_version',
        'settlement_amount_minor',
        'product_id',
        'currency',
    ];

    protected static function bootGuardsFrozenAuctionConfiguration(): void
    {
        static::updating(function (self $model): void {
            // getOriginal, not the current value: an update that changes the
            // status away from draft in the same save must still be judged by
            // the state the auction was actually in.
            $original = $model->getOriginal('status');

            $wasDraft = $original instanceof AuctionStatus
                ? $original === AuctionStatus::Draft
                : $original === AuctionStatus::Draft->value;

            if ($wasDraft) {
                return;
            }

            foreach (self::$frozenColumns as $column) {
                if ($model->isDirty($column)) {
                    throw AuctionConfigurationIsFrozen::for($column);
                }
            }
        });
    }
}

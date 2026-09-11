<?php

declare(strict_types=1);

namespace App\Domain\Operations\Queries;

use Illuminate\Support\Facades\Cache;

/**
 * When each scheduled sweep last ran, read from the cache.
 *
 * Each sweep command stamps its own key after a successful pass. This reads
 * those stamps and presents them on the operations dashboard so a person can
 * see at a glance whether the scheduler is keeping up.
 *
 * NOT A SOURCE OF TRUTH FOR BUSINESS DATA. The timestamps are purely
 * operational: when a command last completed, not what it found. If the cache
 * is cleared, the dashboard shows no last-run data until the next sweep runs,
 * which is correct -- the absence of data is more honest than a stale guess.
 *
 * THIS DOES NOT PRODUCE AN ANOMALY. A missing or old timestamp is information
 * for a person to interpret, not an exception to act on automatically. The
 * schedule is every minute, the lock is five minutes, and a run that happened
 * six minutes ago is within the normal range -- only a person watching the
 * dashboard knows whether something is actually wrong.
 */
class SchedulerStatus
{
    /**
     * Each sweep's cache key, human-readable label, and schedule cadence
     * in minutes (for display only -- staleness is not computed here).
     *
     * @var array<string, array{label: string, cadence_minutes: int}>
     */
    private const SWEEPS = [
        'sweeps:auctions_tick:last_run' => [
            'label' => 'Auction clock',
            'cadence_minutes' => 1,
        ],
        'sweeps:expire_checkouts:last_run' => [
            'label' => 'Checkout expiry',
            'cadence_minutes' => 1,
        ],
        'sweeps:reconcile_refunds:last_run' => [
            'label' => 'Refund reconciliation',
            'cadence_minutes' => 15,
        ],
        'sweeps:reconcile_referrals:last_run' => [
            'label' => 'Referral reconciliation',
            'cadence_minutes' => 0, // Not scheduled; manual only.
        ],
    ];

    /**
     * Read the last-run timestamps from the cache.
     *
     * @return list<array{key: string, label: string, cadence_minutes: int, last_run_at: ?string}>
     */
    public function all(): array
    {
        return array_map(
            fn (string $key, array $meta): array => [
                'key' => $key,
                'label' => $meta['label'],
                'cadence_minutes' => $meta['cadence_minutes'],
                'last_run_at' => Cache::get($key)?->toIso8601String(),
            ],
            array_keys(self::SWEEPS),
            self::SWEEPS,
        );
    }
}

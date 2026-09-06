<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How long a scheduled sweep may hold its overlap lock.
 *
 * WHY THESE ARE NAMED RATHER THAN INLINE. A magic number in `routes/console.php`
 * is a number nobody can test. These are referenced by the schedule and
 * asserted by `tests/Feature/Schedule/SchedulerMutexTest.php`, so the value the
 * scheduler uses and the value the test checks cannot drift apart.
 *
 * WHY THEY EXIST AT ALL. `withoutOverlapping()` defaults to a 24-hour mutex and
 * releases it early only through POSIX signals, behind an
 * `extension_loaded('pcntl')` guard. Development is native Windows, where
 * `pcntl` does not exist, and every sweep runs with `runInBackground()` -- which
 * releases the lock by appending `schedule:finish` to the spawned command, and
 * therefore releases nothing at all if that process is killed. The lock lives in
 * the `database` cache store, so it survives restarts and deployments.
 *
 * With the default, one interrupted sweep stops auctions starting, closing,
 * choosing winners, opening settlement checkouts and forfeiting -- silently,
 * for a day.
 *
 * THE ASYMMETRY THAT SETS THE VALUES. Expiring a lock too early costs one
 * duplicated sweep, which is safe by construction: every step re-reads its row
 * under `SELECT ... FOR UPDATE` and returns unchanged if another run got there
 * first. Expiring it too late costs an outage. So these err short.
 *
 * NEITHER VALUE CHANGES WHAT A SWEEP DOES. They bound only how long a dead run
 * can block the next one.
 */
final class ScheduleLocks
{
    /**
     * Minute-scheduled sweeps that do bounded database work.
     *
     * `auctions:tick` and `orders:expire-checkouts`. Both are capped at 200
     * records per pass and neither waits on a payment provider, so a healthy
     * run finishes in seconds. Five minutes is five times their schedule
     * interval -- generous enough that a legitimate run is never cut short,
     * short enough that a stale lock repairs itself before anybody notices.
     */
    public const SWEEP_MINUTES = 5;

    /**
     * `refunds:reconcile`, which waits on Paystack.
     *
     * Deliberately longer than {@see self::SWEEP_MINUTES}, and the one place
     * code inspection argues against the shorter value. The command makes up to
     * 100 verification calls and then a report pass that asks again, each with
     * `paystack.timeout` seconds to spend. A provider outage can keep one run
     * busy well past its own fifteen-minute schedule, and a five-minute lock
     * would pile several runs onto a host that is already failing.
     *
     * Thirty minutes is twice the schedule, beyond any healthy run, and still
     * self-heals within the hour. Affordable because this command reports and
     * confirms rather than repairing: a stale lock here delays a customer
     * learning their refund landed. It does not stop commerce.
     */
    public const RECONCILE_MINUTES = 30;
}

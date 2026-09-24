<?php

declare(strict_types=1);

use App\Support\ScheduleLocks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Why every overlap lock below carries an explicit expiry
|--------------------------------------------------------------------------
|
| `withoutOverlapping()` defaults to a 1,440-minute (24-hour) mutex, and
| releases it early only through POSIX signal handlers that Laravel installs
| behind `extension_loaded('pcntl')`. Two facts make that default dangerous
| for this application:
|
|   1. Development is native Windows, where `pcntl` does not exist and never
|      will. The signal-release path is therefore dead code here.
|   2. Every sweep below uses `runInBackground()`, which releases the mutex by
|      appending `schedule:finish "<mutex>"` to the spawned command. A process
|      killed before that runs -- a closed terminal, a crash, an OOM kill, a
|      hosting process reaper -- leaves the lock behind.
|
| The mutex lives in the cache store, which is `database` here, so a stale
| lock survives restarts and deployments. With the default expiry, one badly
| timed interruption stops auctions starting, closing, choosing winners,
| opening settlement checkouts and forfeiting -- silently, for a day.
|
| So each lock states its own expiry, chosen from what the command actually
| does rather than from a single blanket number.
|
| THE TRADE-OFF, STATED PLAINLY. Expiring a lock early costs a duplicated
| sweep. That is safe by construction: every step re-reads its row under
| `SELECT ... FOR UPDATE` and returns unchanged if another run got there
| first, which is what `it closes an auction once when several sweeps run
| together` already proves. Expiring it late costs an outage. Given an
| asymmetry that severe, these values err short.
|
| None of this changes what a sweep does -- only how long a dead one can
| block the next.
|
*/

/*
|--------------------------------------------------------------------------
| The auction clock
|--------------------------------------------------------------------------
|
| Auctions run for days, so nothing about their timing may depend on a page
| being open or a process staying alive. This sweep is what notices that a
| start or end time has passed.
|
| Every minute, because an auction's closing window is measured in seconds
| and a coarser schedule would let auctions sit visibly expired. Overlapping
| runs are prevented rather than tolerated: the steps are idempotent, but two
| sweeps racing would still do the same work twice for no benefit.
|
| A missed run delays a closure; it never changes its outcome. The winner is
| resolved from the bid records when the auction actually closes, and those
| records do not change while the sweep is late.
|
*/

/*
 * Five minutes, and this is the lock that matters most.
 *
 * The pass is bounded at 200 auctions and does database work plus, on the
 * closures it produces, an inline email. Five minutes is five times the
 * schedule interval and far beyond any healthy run, so a legitimate sweep is
 * never cut short. It is also the shortest window in which a stale lock
 * repairs itself, and a stale lock here is the worst outcome on the platform:
 * no auction starts, closes, gains a winner, receives a settlement checkout
 * or forfeits until it clears.
 */
Schedule::command('auctions:tick')
    ->everyMinute()
    ->withoutOverlapping(ScheduleLocks::SWEEP_MINUTES)
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Checkout expiry
|--------------------------------------------------------------------------
|
| A Buy Now checkout holds a unit of stock while the customer pays. This is
| what notices they never did, and puts the unit back on sale.
|
| Every minute, because a hold is measured in minutes and stock sitting
| needlessly reserved is stock nobody can buy. Idempotent, so overlapping runs
| expire a checkout once; overlap is prevented anyway.
|
| A missed run delays a release. It never expires a checkout that was paid --
| each order is re-read under a lock and left alone if it has moved on.
|
*/

/*
 * Five minutes, for the same reasons.
 *
 * Bounded at 200 orders and purely database work -- no provider call, no
 * mail -- so a healthy run finishes in seconds. A stale lock here leaves
 * abandoned checkouts holding stock nobody can buy, which is less severe than
 * a stalled auction clock but still takes products off sale.
 */
Schedule::command('orders:expire-checkouts')
    ->everyMinute()
    ->withoutOverlapping(ScheduleLocks::SWEEP_MINUTES)
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Refund reconciliation
|--------------------------------------------------------------------------
|
| Paystack settles refunds asynchronously: it accepts the request, answers
| "pending", and finishes some time later without telling us. This is what
| asks, and it is the only way a refund ever reaches succeeded -- without it
| every refund would sit at processing for ever and no customer would be told
| their money arrived.
|
| Every fifteen minutes, not every minute. A refund is settled by a bank rather
| than by a clock of ours, and asking sixty times an hour would be sixty
| requests to learn the same thing. A missed run delays a confirmation; it
| never changes an outcome and never invents one.
|
| Idempotent, so overlapping runs settle a refund once; overlap is prevented
| anyway. The same pass reports anything where our records and the provider's
| disagree, and repairs none of it.
|
*/

/*
 * Thirty minutes, and deliberately not five.
 *
 * This is the one command where code inspection argues for a longer value
 * than the others. It makes provider calls: up to 100 verifications, then a
 * report pass that asks again, each with `paystack.timeout` (15 seconds by
 * default) to spend. A provider outage could therefore keep one run busy for
 * far longer than its own fifteen-minute schedule, and a five-minute lock
 * would let several pile up against a host that is already failing.
 *
 * Thirty minutes is twice the schedule and comfortably beyond a healthy run,
 * while still self-healing within the hour. That is affordable here in a way
 * it would not be above: this command reports and confirms, it repairs
 * nothing, and a stale lock delays a customer learning their refund landed --
 * it does not stop commerce. Stage 10 already accepts that a missed run
 * delays a confirmation and never changes an outcome.
 */
Schedule::command('refunds:reconcile')
    ->everyFifteenMinutes()
    ->withoutOverlapping(ScheduleLocks::RECONCILE_MINUTES)
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| Unused credit expiry
|--------------------------------------------------------------------------
|
| Promotional credit grants may carry an expiry date. This is what notices the
| date arrived and writes the unspent remainder off the ledger with an
| `EXPIRATION` transaction, so the materialized wallet balance stops counting
| credits nobody can spend any more.
|
| Hourly, not every minute. Expiry is a date, not a hold measured in seconds,
| and an expired grant is already invisible to `spendableBalance()` the moment
| it passes -- the write-off reconciles the balance, it does not decide what
| anyone can spend. A missed run delays that reconciliation; it never writes
| off credits that were spent, because each lot's remainder reaches zero on
| the first pass and is skipped forever after.
|
| Idempotent throughout, so overlapping runs write a wallet off once. The pass
| is bounded at 200 wallets and is purely database work -- no provider call,
| no mail -- so a healthy run finishes in seconds and a five-minute stale lock
| repairs itself well inside the hour.
|
*/

/*
 * Five minutes, the same reasoning as the other local sweeps: bounded,
 * provider-free, database-only. The lock guards against an overlapping run
 * doing the same work twice; it does not change what the work does.
 */
Schedule::command('credits:expire-unused')
    ->hourly()
    ->withoutOverlapping(ScheduleLocks::SWEEP_MINUTES)
    ->runInBackground();

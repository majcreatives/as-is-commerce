<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Services\AuctionClock;
use App\Enums\AuctionStatus;
use App\Support\ScheduleLocks;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * The scheduler overlap lock.
 *
 * `withoutOverlapping()` defaults to a 24-hour mutex and releases it early only
 * through POSIX signal handlers guarded by `extension_loaded('pcntl')`.
 * Development is native Windows, where pcntl does not exist, and every sweep
 * uses `runInBackground()` -- which releases the lock by appending
 * `schedule:finish` to the spawned command, and so releases nothing if that
 * process is killed. The lock lives in the `database` cache store and survives
 * restarts.
 *
 * One interrupted sweep could therefore stop every auction on the platform from
 * progressing, silently, for a day. These tests hold the fix in place: overlap
 * protection is kept, the expiry is explicit and short, a stale lock clears
 * itself, and none of it changed a single business rule.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();
});

/**
 * One scheduled event, by the artisan command it runs.
 */
function scheduledCommand(string $command): Event
{
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $e): bool => str_contains((string) $e->command, $command));

    expect($event)->not->toBeNull("no scheduled event runs {$command}");

    return $event;
}

// ------------------------------------------------------- 1. Overlap protection

it('protects every scheduled sweep against overlapping runs', function (string $command): void {
    expect(scheduledCommand($command)->withoutOverlapping)->toBeTrue(
        "{$command} must keep overlap protection"
    );
})->with(['auctions:tick', 'orders:expire-checkouts', 'refunds:reconcile', 'credits:expire-unused']);

// -------------------------------------------------- 2. Explicit short expiry

it('gives every overlap lock an explicit expiry rather than the 24-hour default', function (string $command): void {
    $event = scheduledCommand($command);

    // 1440 is Laravel's default. Inheriting it is the defect.
    expect($event->expiresAt)->not->toBe(1440)
        ->and($event->expiresAt)->toBeInt()
        ->and($event->expiresAt)->toBeGreaterThan(0);
})->with(['auctions:tick', 'orders:expire-checkouts', 'refunds:reconcile', 'credits:expire-unused']);

it('keeps the database-bound sweeps at the short expiry', function (string $command): void {
    expect(scheduledCommand($command)->expiresAt)->toBe(ScheduleLocks::SWEEP_MINUTES);
})->with(['auctions:tick', 'orders:expire-checkouts', 'credits:expire-unused']);

it('gives the provider-bound reconciler a longer expiry than the local sweeps', function (): void {
    // Longer on purpose: it waits on Paystack, and a five-minute lock would
    // pile runs onto a host that is already failing. Still bounded.
    expect(scheduledCommand('refunds:reconcile')->expiresAt)
        ->toBe(ScheduleLocks::RECONCILE_MINUTES)
        ->toBeGreaterThan(ScheduleLocks::SWEEP_MINUTES);
});

it('keeps every expiry short enough to recover within the hour', function (string $command): void {
    expect(scheduledCommand($command)->expiresAt)->toBeLessThanOrEqual(60);
})->with(['auctions:tick', 'orders:expire-checkouts', 'refunds:reconcile', 'credits:expire-unused']);

// ------------------------------------------------------- 3. A stale mutex expires

it('writes the auction sweep lock with its short expiry, not the default', function (): void {
    $event = scheduledCommand('auctions:tick');

    expect($event->mutex->create($event))->toBeTrue()
        ->and($event->mutex->exists($event))->toBeTrue();

    // Held while it is held: this is still genuine overlap protection.
    expect($event->mutex->create($event))->toBeFalse();

    // And gone once the window the schedule asked for has passed. Travelling
    // past the expiry is what proves the value reached the cache, rather than
    // the 1,440-minute default silently applying.
    $this->travel(ScheduleLocks::SWEEP_MINUTES + 1)->minutes();

    expect($event->mutex->exists($event))->toBeFalse();
});

it('does not clear the lock before its expiry', function (): void {
    $event = scheduledCommand('auctions:tick');
    $event->mutex->create($event);

    $this->travel(ScheduleLocks::SWEEP_MINUTES - 1)->minutes();

    // A legitimate long run must not have its lock pulled out from under it.
    expect($event->mutex->exists($event))->toBeTrue();
});

// ---------------------------------- 4. An interrupted process does not block forever

it('lets the next sweep run after a process died without releasing its lock', function (): void {
    $event = scheduledCommand('auctions:tick');

    // Exactly what an interrupted run leaves behind: the lock created, and
    // `schedule:finish` never reached.
    $event->mutex->create($event);

    expect($event->filtersPass(app()))->toBeFalse();

    $this->travel(ScheduleLocks::SWEEP_MINUTES + 1)->minutes();

    // Self-healed. Under the old default this would have stayed blocked for
    // the best part of a day.
    expect($event->filtersPass(app()))->toBeTrue();
});

it('expires the reconciler lock too, at its own longer window', function (): void {
    $event = scheduledCommand('refunds:reconcile');

    expect($event->mutex->create($event))->toBeTrue();

    // Still held where the five-minute sweeps would already have let go: the
    // longer value is deliberate, not an oversight.
    $this->travel(ScheduleLocks::SWEEP_MINUTES + 1)->minutes();
    expect($event->mutex->exists($event))->toBeTrue();

    // And released well inside the day Laravel's default would have taken.
    $this->travel(ScheduleLocks::RECONCILE_MINUTES)->minutes();
    expect($event->mutex->exists($event))->toBeFalse();
});

// ------------------------------- 5. Auction progression survives an interruption

it('closes an auction correctly after a sweep was interrupted and the lock expired', function (): void {
    $auction = liveAuction();
    $winner = bidder();
    placeBid($auction, $winner, 150);

    $event = scheduledCommand('auctions:tick');

    // A sweep starts, takes the lock, and is killed.
    $event->mutex->create($event);
    expect($event->filtersPass(app()))->toBeFalse();

    // The auction's clock runs out while nothing is sweeping. Time is moved
    // rather than `ends_at` rewritten: a CHECK constraint refuses an end
    // before a start, and moving the clock is what actually happens anyway.
    $this->travel(ScheduleLocks::SWEEP_MINUTES + 10)->minutes();

    expect($event->filtersPass(app()))->toBeTrue();

    // The delayed sweep reaches the same outcome it would have reached on
    // time: the winner comes from the bid records, which did not move.
    $this->artisan('auctions:tick')->assertSuccessful();

    $closed = $auction->fresh();

    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->winner_user_id)->toBe($winner->id)
        ->and($closed->winning_bid_id)->not->toBeNull();
});

it('still closes an auction exactly once when two sweeps run together', function (): void {
    $auction = liveAuction();
    $winner = bidder();
    placeBid($auction, $winner, 100);

    // Past its end, by the clock rather than by editing the record.
    $this->travel(10)->minutes();

    // The cost of expiring a lock early is a duplicated sweep, and that is
    // safe by construction. This is what makes erring short the right call.
    $this->artisan('auctions:tick')->assertSuccessful();
    $this->artisan('auctions:tick')->assertSuccessful();

    $closed = $auction->fresh();

    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->winner_user_id)->toBe($winner->id)
        ->and($closed->settlementOrder()->count())->toBe(1);
});

// ------------------------------------------- 6-10. No business logic was touched

it('changes nothing about how the clock decides an auction has expired', function (): void {
    $clock = app(AuctionClock::class);
    $auction = liveAuction();

    // Live: the stored end time has not arrived.
    expect($clock->hasExpired($auction->fresh()))->toBeFalse()
        ->and($clock->secondsRemaining($auction->fresh()))->toBeGreaterThan(0);

    $this->travel(10)->minutes();

    expect($clock->hasExpired($auction->fresh()))->toBeTrue();

    // The stored timestamp is the only authority, and the lock expiry has no
    // bearing on it whatsoever.
    expect($clock->secondsRemaining($auction->fresh()))->toBe(0);
});

it('changes nothing about placing a bid', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    $bid = placeBid($auction, $user, 150);

    expect($bid->amount_credits)->toBe(150)
        ->and($bid->credit_transaction_id)->not->toBeNull()
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(850)
        ->and($auction->fresh()->highest_bid_id)->toBe($bid->id);
});

it('changes nothing about closing an auction', function (): void {
    $auction = liveAuction();
    $low = bidder();
    $high = bidder();

    placeBid($auction, $low, 100);
    placeBid($auction, $high, 200);

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    // The highest valid credit bid wins. Unchanged.
    expect($closed->winner_user_id)->toBe($high->id)
        ->and($closed->status)->toBe(AuctionStatus::PendingSettlement);
});

it('changes nothing about a Buy Now ending an auction', function (): void {
    $product = stockedProduct();
    $auction = liveAuction(product: $product);
    $bidder = bidder();

    placeBid($auction, $bidder, 100);

    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product->fresh(), $auction->fresh());
    payOrder($order);

    $ended = $auction->fresh();

    // The buyer takes it; the standing highest bidder does not win and gets
    // nothing back.
    expect($ended->buy_now_user_id)->toBe($buyer->id)
        ->and($ended->winner_user_id)->toBeNull()
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(900);
});

it('changes nothing about inventory, credits, payment or settlement', function (): void {
    $product = stockedProduct();
    $auction = liveAuction(product: $product);
    $winner = bidder(1_000);

    // Publishing reserved the unit.
    expect($product->fresh()->stock_reserved)->toBe(1);

    placeBid($auction, $winner, 250);
    expect(creditWalletFor($winner)->fresh()->balance)->toBe(750);

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);
    $order = $closed->settlementOrder()->first();

    expect($order)->not->toBeNull()
        // A settlement is its own GHS figure, never a conversion of credits.
        ->and($order->total_minor)->toBe($closed->settlement_amount_minor)
        ->and($order->discount_minor)->toBe(0);

    payOrder($order);

    $sold = $product->fresh();

    expect($auction->fresh()->status)->toBe(AuctionStatus::Settled)
        ->and($sold->stock_on_hand)->toBe(0)
        ->and($sold->stock_reserved)->toBe(0)
        // Credits stay consumed through every outcome, winning included.
        ->and(creditWalletFor($winner)->fresh()->balance)->toBe(750);
});

it('leaves the scheduled commands themselves untouched', function (): void {
    // Same sweeps as before plus the credit expiry worker, same frequencies.
    // Only the lock expiry moved.
    $schedule = collect(app(Schedule::class)->events())
        ->map(fn (Event $e): string => $e->getExpression())
        ->values()
        ->all();

    expect(scheduledCommand('auctions:tick')->getExpression())->toBe('* * * * *')
        ->and(scheduledCommand('orders:expire-checkouts')->getExpression())->toBe('* * * * *')
        ->and(scheduledCommand('refunds:reconcile')->getExpression())->toBe('*/15 * * * *')
        ->and(scheduledCommand('credits:expire-unused')->getExpression())->toBe('0 * * * *')
        ->and($schedule)->toHaveCount(4);
});

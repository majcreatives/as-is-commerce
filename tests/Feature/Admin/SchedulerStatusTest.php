<?php

declare(strict_types=1);

use App\Domain\Operations\Queries\SchedulerStatus;
use App\Livewire\Admin\Operations\OperationsDashboard;
use Illuminate\Support\Facades\Cache;

/*
 * The scheduler heartbeat.
 *
 * Each sweep command stamps a cache key after a successful pass. This reads
 * those stamps and presents them on the operations dashboard.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

// --------------------------------------------------------------- Stamps

it('auctions:tick stamps the cache', function (): void {
    Cache::forget('sweeps:auctions_tick:last_run');

    $this->artisan('auctions:tick');

    expect(Cache::has('sweeps:auctions_tick:last_run'))->toBeTrue();
});

it('orders:expire-checkouts stamps the cache', function (): void {
    Cache::forget('sweeps:expire_checkouts:last_run');

    $this->artisan('orders:expire-checkouts');

    expect(Cache::has('sweeps:expire_checkouts:last_run'))->toBeTrue();
});

it('refunds:reconcile stamps the cache', function (): void {
    Cache::forget('sweeps:reconcile_refunds:last_run');

    $this->artisan('refunds:reconcile');

    expect(Cache::has('sweeps:reconcile_refunds:last_run'))->toBeTrue();
});

it('referrals:reconcile stamps the cache', function (): void {
    Cache::forget('sweeps:reconcile_referrals:last_run');

    $this->artisan('referrals:reconcile');

    expect(Cache::has('sweeps:reconcile_referrals:last_run'))->toBeTrue();
});

it('credits:expire-unused stamps the cache', function (): void {
    Cache::forget('sweeps:expire_unused:last_run');

    $this->artisan('credits:expire-unused');

    expect(Cache::has('sweeps:expire_unused:last_run'))->toBeTrue();
});

// --------------------------------------------------------------- Query

it('reads all sweep timestamps from the cache', function (): void {
    $now = now()->toIso8601String();
    Cache::put('sweeps:auctions_tick:last_run', $now);
    Cache::put('sweeps:expire_checkouts:last_run', $now);
    Cache::put('sweeps:reconcile_refunds:last_run', $now);
    Cache::put('sweeps:reconcile_referrals:last_run', $now);
    Cache::put('sweeps:expire_unused:last_run', $now);

    $status = app(SchedulerStatus::class)->all();

    expect($status)->toHaveCount(5);

    foreach ($status as $sweep) {
        expect($sweep['last_run_at'])->toBe($now);
    }
});

it('shows null for sweeps that have never run', function (): void {
    Cache::flush();

    $status = app(SchedulerStatus::class)->all();

    foreach ($status as $sweep) {
        expect($sweep['last_run_at'])->toBeNull();
    }
});

// --------------------------------------------------------------- Dashboard

it('shows sweep timestamps on the dashboard', function (): void {
    Cache::put('sweeps:auctions_tick:last_run', now()->toIso8601String());

    Livewire::actingAs($this->admin)
        ->test(OperationsDashboard::class)
        ->assertSee('Auction clock')
        ->assertSee('Scheduler');
});

it('is reachable by an administrator', function (): void {
    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
});

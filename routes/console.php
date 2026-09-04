<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

Schedule::command('auctions:tick')
    ->everyMinute()
    ->withoutOverlapping()
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

Schedule::command('orders:expire-checkouts')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

<?php

declare(strict_types=1);

use App\Models\Auction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * `auctions:verify-snapshots` finds an auction the engine cannot read BEFORE a
 * customer does. It reports and repairs nothing, so the tests hold both halves:
 * it notices a real problem, and it changes nothing when it does.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

function verifySnapshots(): array
{
    $code = Artisan::call('auctions:verify-snapshots');

    return [$code, Artisan::output()];
}

it('passes when every snapshot loads and agrees with its bids', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 30);
    liveAuction();

    [$code, $output] = verifySnapshots();

    expect($code)->toBe(0)
        ->and($output)->toContain('Checked 2 auction(s)')
        ->and($output)->toContain('single_highest: 2')
        ->and($output)->toContain('Every snapshot loads');
});

it('passes on an empty database rather than failing for having nothing to check', function (): void {
    [$code, $output] = verifySnapshots();

    expect($code)->toBe(0)
        ->and($output)->toContain('Checked 0 auction(s)');
});

it('reports a snapshot the engine refuses, and exits non-zero', function (): void {
    // A DRAFT auction, because only a draft's configuration may be edited --
    // which is exactly why this state is reachable by hand-written SQL and not
    // through the application.
    $draft = Auction::factory()->create();

    DB::table('auctions')->where('id', $draft->id)->update([
        'rules_snapshot' => DB::raw("JSON_SET(rules_snapshot, '\$.rules.snapshot_version', 3)"),
    ]);

    [$code, $output] = verifySnapshots();

    expect($code)->toBe(1)
        ->and($output)->toContain('unreadable snapshot')
        ->and($output)->toContain((string) $draft->id);
});

it('reports a bid model the engine cannot honour', function (): void {
    $draft = Auction::factory()->create();

    DB::table('auctions')->where('id', $draft->id)->update([
        'rules_snapshot' => DB::raw(
            "JSON_SET(rules_snapshot, '\$.rules.bid_model', 'cumulative_step', '\$.rules.winner_rule', 'highest_cumulative_credits')"
        ),
    ]);

    [$code, $output] = verifySnapshots();

    expect($code)->toBe(1)
        ->and($output)->toContain('cannot honour');
});

it('reports a projection that disagrees with the bid records', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 30);

    // Around the model, which refuses to write this column: the point is to
    // reproduce the drift a hand-run statement or a restore could cause.
    DB::table('auctions')->where('id', $auction->id)->update(['highest_bid_credits' => 999]);

    [$code, $output] = verifySnapshots();

    expect($code)->toBe(1)
        ->and($output)->toContain('projection disagrees');
});

it('repairs nothing when it finds a problem', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 30);
    DB::table('auctions')->where('id', $auction->id)->update(['highest_bid_credits' => 999]);

    verifySnapshots();

    // Still wrong. A human decides which record is right, and fixes it with a
    // deliberate act -- not a check that happened to run.
    expect((int) DB::table('auctions')->where('id', $auction->id)->value('highest_bid_credits'))->toBe(999);
});

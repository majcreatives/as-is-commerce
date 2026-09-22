<?php

declare(strict_types=1);

use App\Models\Auction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Step 3 of docs/PLAN_POT_TARGET_BIDDING.md: schema for the pot target.
 *
 * WHY THIS LIVES HERE. The migration drops and recreates the
 * `auctions_frozen_configuration` trigger, and DDL commits the transaction it
 * runs in -- the Feature suite's rolled-back transaction cannot host it. This
 * suite truncates instead, the same reason AuctionSnapshotMigrationTest and
 * CreditRedenominationMigrationTest live here.
 *
 * WHAT IS BEING PROVED. The column is nullable and every existing auction is
 * untouched by adding it (D-4's "no default"); the freeze trigger still
 * refuses every other frozen column, now also refuses editing the target once
 * an auction leaves Draft, and still allows editing it while still a Draft;
 * the widened CHECK constraint accepts the new closure reason and still
 * refuses an unrecognised one; and the round trip back down restores the
 * schema exactly.
 *
 * The test database already carries this migration (it is part of the
 * baseline every test migrates to), so "before" is reached by calling
 * `down()` deliberately, not by skipping the migration.
 */

const POT_TARGET_MIGRATION = '2026_09_22_110000_add_pot_target_to_auctions.php';

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

afterEach(function (): void {
    // A failed test must not leave the column or trigger missing for the
    // tests that run after it.
    if (! DB::getSchemaBuilder()->hasColumn('auctions', 'pot_target_credits')) {
        potTargetMigration()->up();
    }
});

function potTargetMigration(): object
{
    return require database_path('migrations/'.POT_TARGET_MIGRATION);
}

function frozenConfigurationTriggerExists(): bool
{
    return DB::selectOne(
        'SELECT COUNT(*) AS n FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
        ['auctions_frozen_configuration'],
    )->n > 0;
}

it('adds the column as nullable, and leaves every existing auction untouched', function (): void {
    $auction = liveAuction();
    $before = DB::table('auctions')->where('id', $auction->id)->first();

    potTargetMigration()->down();
    expect(DB::getSchemaBuilder()->hasColumn('auctions', 'pot_target_credits'))->toBeFalse();

    potTargetMigration()->up();

    $after = DB::table('auctions')->where('id', $auction->id)->first();

    expect($after->pot_target_credits)->toBeNull()
        ->and($after->settlement_amount_minor)->toBe($before->settlement_amount_minor)
        ->and($after->rules_snapshot)->toBe($before->rules_snapshot);
});

it('refuses a zero or negative target, and accepts a positive one', function (): void {
    $draft = Auction::factory()->create();

    expect(fn () => DB::table('auctions')->where('id', $draft->id)->update(['pot_target_credits' => 0]))
        ->toThrow(QueryException::class);

    DB::table('auctions')->where('id', $draft->id)->update(['pot_target_credits' => 1_500_000]);

    expect(DB::table('auctions')->where('id', $draft->id)->value('pot_target_credits'))->toBe(1_500_000);
});

it('freezes the target once the auction leaves draft, alongside everything else', function (): void {
    $auction = liveAuction();

    expect(fn () => DB::table('auctions')->where('id', $auction->id)->update(['pot_target_credits' => 999]))
        ->toThrow(QueryException::class, 'frozen');
});

it('still allows setting the target while the auction is a draft', function (): void {
    $draft = Auction::factory()->create();

    DB::table('auctions')->where('id', $draft->id)->update(['pot_target_credits' => 250_000]);

    expect(DB::table('auctions')->where('id', $draft->id)->value('pot_target_credits'))->toBe(250_000);
});

it('leaves every other frozen column refused exactly as before', function (): void {
    $auction = liveAuction();

    expect(fn () => DB::table('auctions')->where('id', $auction->id)->update([
        'settlement_amount_minor' => 1,
    ]))->toThrow(QueryException::class, 'frozen');
});

it('accepts the new closure reason and still refuses an unrecognised one', function (): void {
    $auction = liveAuction();

    DB::table('auctions')->where('id', $auction->id)->update(['closure_reason' => 'pot_target_reached']);
    expect(DB::table('auctions')->where('id', $auction->id)->value('closure_reason'))->toBe('pot_target_reached');

    expect(fn () => DB::table('auctions')->where('id', $auction->id)->update(['closure_reason' => 'made_up_reason']))
        ->toThrow(QueryException::class);
});

it('restores the frozen-configuration trigger and drops the column on the way down', function (): void {
    expect(frozenConfigurationTriggerExists())->toBeTrue();

    potTargetMigration()->down();

    expect(frozenConfigurationTriggerExists())->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumn('auctions', 'pot_target_credits'))->toBeFalse();

    // Round trip: back up leaves the schema exactly as every other test in
    // this file assumes, for whatever runs after this one.
    potTargetMigration()->up();

    expect(DB::getSchemaBuilder()->hasColumn('auctions', 'pot_target_credits'))->toBeTrue();
});

it('refuses the pot_target_reached closure reason after rolling back', function (): void {
    $auction = liveAuction();

    potTargetMigration()->down();

    expect(fn () => DB::table('auctions')->where('id', $auction->id)->update(['closure_reason' => 'pot_target_reached']))
        ->toThrow(QueryException::class);

    potTargetMigration()->up();
});

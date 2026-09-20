<?php

declare(strict_types=1);

use App\Models\Auction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The migration that rewrites every stored auction snapshot from version 3 to 4.
 *
 * WHY THIS LIVES HERE. The migration drops and recreates a database trigger,
 * and DDL commits the transaction it runs in. The Feature suite wraps each test
 * in a transaction; running this there would commit the test's rows and leak
 * them into every test after it. This suite truncates instead.
 *
 * THE PROMISE BEING TESTED. Every existing auction behaves identically
 * afterwards: two keys change, nothing else moves, and the freeze trigger is
 * back and still refusing edits. A frozen snapshot is the most important
 * invariant in the codebase, and a migration is the one moment it is touched.
 */

const SNAPSHOT_MIGRATION = '2026_09_20_100100_rewrite_auction_snapshots_to_version_4.php';

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

afterEach(function (): void {
    // A failed test must not leave every auction editable for the tests after
    // it. Running the migration restores the trigger whatever else it does.
    if (! snapshotTriggerExists()) {
        snapshotMigration()->up();
    }
});

function snapshotMigration(): object
{
    return require database_path('migrations/'.SNAPSHOT_MIGRATION);
}

function snapshotTriggerExists(): bool
{
    return DB::selectOne(
        'SELECT COUNT(*) AS n FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
        ['auctions_frozen_configuration'],
    )->n > 0;
}

/**
 * Put an auction's snapshot back to the version 3 shape, as it was before the
 * migration existed. The freeze trigger is dropped to do it, and the migration
 * under test is what brings it back.
 */
function downgradeToVersionThree(Auction $auction): void
{
    DB::unprepared('DROP TRIGGER IF EXISTS auctions_frozen_configuration');

    DB::statement(
        "UPDATE auctions
         SET rules_snapshot = JSON_SET(JSON_REMOVE(rules_snapshot, '\$.rules.bid_model'), '\$.rules.snapshot_version', 3)
         WHERE id = ?",
        [$auction->id],
    );
}

function storedRules(Auction $auction): array
{
    return json_decode(
        (string) DB::table('auctions')->where('id', $auction->id)->value('rules_snapshot'),
        true,
    )['rules'];
}

it('upgrades a live version 3 snapshot and changes nothing but the two keys', function (): void {
    $auction = liveAuction();
    $before = storedRules($auction);

    downgradeToVersionThree($auction);
    expect(storedRules($auction)['snapshot_version'])->toBe(3);

    snapshotMigration()->up();

    $after = storedRules($auction);

    expect($after['snapshot_version'])->toBe(4)
        ->and($after['bid_model'])->toBe('single_highest');

    // Everything else, key for key: the rules the auction was created under.
    unset($before['snapshot_version'], $before['bid_model'], $after['snapshot_version'], $after['bid_model']);
    ksort($before);
    ksort($after);

    expect($after)->toBe($before);
});

it('leaves the auction readable by the engine and behaving as it did', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 40);

    downgradeToVersionThree($auction);
    snapshotMigration()->up();

    $reloaded = Auction::query()->findOrFail($auction->id);

    expect($reloaded->rules()->bidModel->value)->toBe('single_highest')
        ->and($reloaded->winnerRule())->toBe('highest_valid_credit_bid')
        ->and($reloaded->status)->toBe($auction->status)
        ->and($reloaded->bid_count)->toBe(1);
});

it('upgrades a draft as well as a live auction', function (): void {
    $draft = Auction::factory()->create();

    downgradeToVersionThree($draft);
    snapshotMigration()->up();

    expect(storedRules($draft)['snapshot_version'])->toBe(4);
});

it('restores the freeze trigger, and it still refuses to edit a live auction', function (): void {
    $auction = liveAuction();

    downgradeToVersionThree($auction);
    expect(snapshotTriggerExists())->toBeFalse();

    snapshotMigration()->up();

    expect(snapshotTriggerExists())->toBeTrue();

    expect(fn () => DB::table('auctions')->where('id', $auction->id)->update([
        'rules_snapshot' => DB::raw("JSON_SET(rules_snapshot, '\$.rules.tax_bps', 5000)"),
    ]))->toThrow(QueryException::class, 'frozen');
});

it('is safe to run twice', function (): void {
    $auction = liveAuction();

    downgradeToVersionThree($auction);
    snapshotMigration()->up();
    $once = storedRules($auction);

    snapshotMigration()->up();

    expect(storedRules($auction))->toBe($once);
});

it('stops on a snapshot version it does not understand, and touches nothing', function (): void {
    $understood = Auction::factory()->create();
    $stranger = Auction::factory()->create();

    // Both are drafts, so their snapshots are editable without dropping the
    // trigger -- which is what lets this prove the migration stops BEFORE it
    // does anything, not merely that it can restore what it broke.
    DB::statement("UPDATE auctions SET rules_snapshot = JSON_SET(JSON_REMOVE(rules_snapshot, '\$.rules.bid_model'), '\$.rules.snapshot_version', 3) WHERE id = ?", [$understood->id]);
    DB::statement("UPDATE auctions SET rules_snapshot = JSON_SET(rules_snapshot, '\$.rules.snapshot_version', 1) WHERE id = ?", [$stranger->id]);

    expect(fn () => snapshotMigration()->up())
        ->toThrow(RuntimeException::class, 'Cannot migrate auction snapshots');

    expect(storedRules($understood)['snapshot_version'])->toBe(3)
        ->and(storedRules($stranger)['snapshot_version'])->toBe(1)
        ->and(snapshotTriggerExists())->toBeTrue();
});

it('stops on a winner rule that is not the single-highest one', function (): void {
    $draft = Auction::factory()->create();

    DB::statement(
        "UPDATE auctions
         SET rules_snapshot = JSON_SET(JSON_REMOVE(rules_snapshot, '\$.rules.bid_model'), '\$.rules.snapshot_version', 3, '\$.rules.winner_rule', 'last_bidder_standing')
         WHERE id = ?",
        [$draft->id],
    );

    expect(fn () => snapshotMigration()->up())
        ->toThrow(RuntimeException::class, 'winner rule');

    expect(storedRules($draft)['snapshot_version'])->toBe(3);
});

it('restores version 3 on the way down when every auction is single-highest', function (): void {
    $auction = liveAuction();
    $before = storedRules($auction);

    snapshotMigration()->down();

    $down = storedRules($auction);

    expect($down['snapshot_version'])->toBe(3)
        ->and($down)->not->toHaveKey('bid_model')
        ->and(snapshotTriggerExists())->toBeTrue();

    // And back up again: down then up is a round trip.
    snapshotMigration()->up();

    expect(storedRules($auction))->toBe($before);
});

it('refuses to write version 3 for an auction that follows another model', function (): void {
    $draft = Auction::factory()->create();

    DB::statement(
        "UPDATE auctions SET rules_snapshot = JSON_SET(rules_snapshot, '\$.rules.bid_model', 'cumulative_step') WHERE id = ?",
        [$draft->id],
    );

    // Version 3 cannot describe it, and writing it would make the snapshot lie.
    expect(fn () => snapshotMigration()->down())
        ->toThrow(RuntimeException::class, 'cannot describe');

    expect(storedRules($draft)['bid_model'])->toBe('cumulative_step');
});

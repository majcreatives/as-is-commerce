<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\ActivateRuleset;
use App\Domain\Auction\Actions\ArchiveRuleset;
use App\Domain\Auction\Actions\CreateRuleset;
use App\Domain\Auction\Actions\CreateRulesetVersion;
use App\Domain\Auction\Actions\UpdateRuleset;
use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\Exceptions\RulesetNotEditable;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array<string, mixed>
 */
function rulesetAttributes(array $overrides = []): array
{
    return array_merge([
        'name' => 'Standard Auction',
        'description' => 'Test configuration.',
        'minimum_bid_credits' => null,
        'minimum_bid_increment_credits' => null,
        'allow_bid_increase' => null,
        'minimum_bid_interval_ms' => 3000,
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 10,
        'extension_seconds' => 10,
        'max_extensions' => 20,
        'max_extension_total_seconds' => 300,
        'checkout_deadline_minutes' => 60,
        'forfeit_policy' => 'relist',
        'buy_now_enabled' => true,
        'buy_now_credit_discount_enabled' => true,
        'delivery_fee_minor' => 0,
        'currency' => 'GHS',
        'tax_bps' => 0,
    ], $overrides);
}

// ------------------------------------------------------------------ Create

it('creates a ruleset as a draft', function (): void {
    $ruleset = app(CreateRuleset::class)->handle(rulesetAttributes());

    expect($ruleset->status)->toBe(RulesetStatus::Draft)
        ->and($ruleset->version)->toBe(1)
        ->and($ruleset->is_default)->toBeFalse()
        ->and($ruleset->activated_at)->toBeNull();
});

it('never creates a ruleset already active, even if asked to', function (): void {
    // Status is not fillable, so a crafted payload cannot promote itself.
    $ruleset = app(CreateRuleset::class)->handle(
        rulesetAttributes(['status' => 'active', 'is_default' => true])
    );

    expect($ruleset->status)->toBe(RulesetStatus::Draft)
        ->and($ruleset->is_default)->toBeFalse();
});

it('numbers versions per named lineage', function (): void {
    $create = app(CreateRuleset::class);

    expect($create->handle(rulesetAttributes(['name' => 'Standard']))->version)->toBe(1)
        ->and($create->handle(rulesetAttributes(['name' => 'Standard']))->version)->toBe(2)
        ->and($create->handle(rulesetAttributes(['name' => 'Flash']))->version)->toBe(1);
});

it('records who created the ruleset', function (): void {
    $admin = userWithRole('admin');

    $ruleset = app(CreateRuleset::class)->handle(rulesetAttributes(), $admin);

    expect($ruleset->created_by)->toBe($admin->id);
});

// -------------------------------------------------------------- Validation

it('rejects a contradictory closing window', function (): void {
    expect(fn (): AuctionRuleset => app(CreateRuleset::class)->handle(rulesetAttributes([
        'base_duration_seconds' => 60,
        'closing_window_seconds' => 120,
    ])))->toThrow(InvalidAuctionRules::class);
});

it('rejects an extension budget shorter than one extension', function (): void {
    expect(fn (): AuctionRuleset => app(CreateRuleset::class)->handle(rulesetAttributes([
        'extension_seconds' => 30,
        'max_extensions' => 5,
        'max_extension_total_seconds' => 10,
    ])))->toThrow(InvalidAuctionRules::class);
});

it('rejects extensions that could never trigger', function (): void {
    expect(fn (): AuctionRuleset => app(CreateRuleset::class)->handle(rulesetAttributes([
        'closing_window_seconds' => 0,
        'extension_seconds' => 10,
        'max_extensions' => 5,
    ])))->toThrow(InvalidAuctionRules::class);
});

it('accepts a configuration with extensions disabled', function (): void {
    $ruleset = app(CreateRuleset::class)->handle(rulesetAttributes([
        'closing_window_seconds' => 0,
        'extension_seconds' => 0,
        'max_extensions' => 0,
        'max_extension_total_seconds' => 0,
    ]));

    expect($ruleset->max_extensions)->toBe(0);
});

it('is rejected by the database when the minimum bid is zero', function (): void {
    // Belt and braces: even bypassing the action, the row cannot be written.
    // Null is allowed -- that means no minimum -- but zero is not a rule.
    expect(fn () => AuctionRuleset::factory()->create(['minimum_bid_credits' => 0]))
        ->toThrow(QueryException::class);
});

it('is rejected by the database when the minimum increment is zero', function (): void {
    expect(fn () => AuctionRuleset::factory()->create(['minimum_bid_increment_credits' => 0]))
        ->toThrow(QueryException::class);
});

it('is rejected by the database when the base duration is zero', function (): void {
    expect(fn () => AuctionRuleset::factory()->create(['base_duration_seconds' => 0]))
        ->toThrow(QueryException::class);
});

/*
 * The per-credit discount rate was removed by the Stage 16.5 correction, so
 * the schema must not be able to express one -- a column that ghosted back in
 * would let a "rate" silently override lot-based valuation.
 */
it('no longer has a column for a discount rate', function (): void {
    expect(Schema::hasColumn('auction_rulesets', 'buy_now_credit_discount_minor_per_credit'))
        ->toBeFalse();
});

/*
 * Null is a legitimate value for every undecided bid rule, and must not be
 * confused with an invalid one.
 */
it('accepts undecided bid rules as null', function (): void {
    $ruleset = AuctionRuleset::factory()->create([
        'minimum_bid_credits' => null,
        'minimum_bid_increment_credits' => null,
        'allow_bid_increase' => null,
    ]);

    expect($ruleset->minimum_bid_credits)->toBeNull()
        ->and($ruleset->minimum_bid_increment_credits)->toBeNull()
        ->and($ruleset->allow_bid_increase)->toBeNull();
});

// ------------------------------------------------------------------ Update

it('updates a draft', function (): void {
    $draft = AuctionRuleset::factory()->create(['minimum_bid_credits' => 1]);

    app(UpdateRuleset::class)->handle($draft, ['minimum_bid_credits' => 5]);

    expect($draft->fresh()?->minimum_bid_credits)->toBe(5);
});

it('refuses to mutate an active ruleset', function (): void {
    $active = AuctionRuleset::factory()->active()->create(['minimum_bid_credits' => 1]);

    expect(fn (): AuctionRuleset => app(UpdateRuleset::class)->handle($active, ['minimum_bid_credits' => 99]))
        ->toThrow(RulesetNotEditable::class);

    expect($active->fresh()?->minimum_bid_credits)->toBe(1);
});

it('refuses to mutate an archived ruleset', function (): void {
    $archived = AuctionRuleset::factory()->archived()->create(['minimum_bid_credits' => 2]);

    expect(fn (): AuctionRuleset => app(UpdateRuleset::class)->handle($archived, ['minimum_bid_credits' => 99]))
        ->toThrow(RulesetNotEditable::class);

    expect($archived->fresh()?->minimum_bid_credits)->toBe(2);
});

it('rejects an update that would make the configuration contradictory', function (): void {
    $draft = AuctionRuleset::factory()->create(['base_duration_seconds' => 300]);

    expect(fn (): AuctionRuleset => app(UpdateRuleset::class)->handle($draft, ['closing_window_seconds' => 999]))
        ->toThrow(InvalidAuctionRules::class);
});

// ---------------------------------------------------------------- Activate

it('activates a draft', function (): void {
    $draft = AuctionRuleset::factory()->create();

    app(ActivateRuleset::class)->handle($draft);

    expect($draft->fresh())
        ->status->toBe(RulesetStatus::Active)
        ->activated_at->not->toBeNull();
});

it('archives the previous active version of the same lineage', function (): void {
    $v1 = AuctionRuleset::factory()->active()->create(['name' => 'Standard', 'version' => 1]);
    $v2 = AuctionRuleset::factory()->create(['name' => 'Standard', 'version' => 2]);

    app(ActivateRuleset::class)->handle($v2);

    expect($v1->fresh()?->status)->toBe(RulesetStatus::Archived)
        ->and($v2->fresh()?->status)->toBe(RulesetStatus::Active);
});

it('leaves a different lineage alone when activating', function (): void {
    $standard = AuctionRuleset::factory()->active()->create(['name' => 'Standard']);
    $flash = AuctionRuleset::factory()->create(['name' => 'Flash']);

    app(ActivateRuleset::class)->handle($flash);

    expect($standard->fresh()?->status)->toBe(RulesetStatus::Active)
        ->and($flash->fresh()?->status)->toBe(RulesetStatus::Active);
});

it('carries the default flag to the superseding version', function (): void {
    $v1 = AuctionRuleset::factory()->default()->create(['name' => 'Standard', 'version' => 1]);
    $v2 = AuctionRuleset::factory()->create(['name' => 'Standard', 'version' => 2]);

    app(ActivateRuleset::class)->handle($v2);

    expect($v1->fresh()?->is_default)->toBeFalse()
        ->and($v2->fresh()?->is_default)->toBeTrue();
});

it('refuses to activate an archived ruleset', function (): void {
    $archived = AuctionRuleset::factory()->archived()->create();

    expect(fn (): AuctionRuleset => app(ActivateRuleset::class)->handle($archived))
        ->toThrow(RulesetNotEditable::class);
});

// ----------------------------------------------------------------- Archive

it('archives an active ruleset', function (): void {
    $active = AuctionRuleset::factory()->active()->create();

    app(ArchiveRuleset::class)->handle($active);

    expect($active->fresh())
        ->status->toBe(RulesetStatus::Archived)
        ->archived_at->not->toBeNull();
});

it('archives a draft that is no longer wanted', function (): void {
    $draft = AuctionRuleset::factory()->create();

    app(ArchiveRuleset::class)->handle($draft);

    expect($draft->fresh()?->status)->toBe(RulesetStatus::Archived);
});

/*
 * Archiving the default would leave auction creation with nothing to fall
 * back on, so it has to be a deliberate two-step act.
 */
it('refuses to archive the default ruleset', function (): void {
    $default = AuctionRuleset::factory()->default()->create();

    expect(fn (): AuctionRuleset => app(ArchiveRuleset::class)->handle($default))
        ->toThrow(DomainException::class);

    expect($default->fresh()?->status)->toBe(RulesetStatus::Active);
});

it('never deletes an archived ruleset, so past configuration stays readable', function (): void {
    $active = AuctionRuleset::factory()->active()->create();

    app(ArchiveRuleset::class)->handle($active);

    expect(AuctionRuleset::find($active->id))->not->toBeNull();
});

// ---------------------------------------------------------- New version

it('drafts a new version from an active ruleset', function (): void {
    $active = AuctionRuleset::factory()->active()->create([
        'name' => 'Standard',
        'version' => 1,
        'minimum_bid_credits' => 3,
    ]);

    $draft = app(CreateRulesetVersion::class)->handle($active);

    expect($draft->name)->toBe('Standard')
        ->and($draft->version)->toBe(2)
        ->and($draft->status)->toBe(RulesetStatus::Draft)
        ->and($draft->is_default)->toBeFalse()
        // Values are copied so the administrator edits from where they were.
        ->and($draft->minimum_bid_credits)->toBe(3)
        // The original is untouched.
        ->and($active->fresh()?->status)->toBe(RulesetStatus::Active);
});

// ------------------------------------------------------- Database integrity

it('will not allow two active versions of one lineage', function (): void {
    AuctionRuleset::factory()->active()->create(['name' => 'Standard', 'version' => 1]);

    expect(fn () => AuctionRuleset::factory()->active()->create(['name' => 'Standard', 'version' => 2]))
        ->toThrow(QueryException::class);
});

it('will not allow two default rulesets', function (): void {
    AuctionRuleset::factory()->default()->create(['name' => 'Standard']);

    expect(fn () => AuctionRuleset::factory()->default()->create(['name' => 'Flash']))
        ->toThrow(QueryException::class);
});

it('will not allow a duplicate name and version', function (): void {
    AuctionRuleset::factory()->create(['name' => 'Standard', 'version' => 1]);

    expect(fn () => AuctionRuleset::factory()->create(['name' => 'Standard', 'version' => 1]))
        ->toThrow(QueryException::class);
});

/*
 * The schema default must agree with what the seeder, the factory and the
 * admin form all register. A column default nobody reads becomes the rule
 * that applies when a ruleset is created without an explicit interval, so a
 * stale default is a decision made by accident.
 */
it('keeps the schema default for the bid interval in step with the configured one', function (): void {
    $default = DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
        ->where('TABLE_NAME', 'auction_rulesets')
        ->where('COLUMN_NAME', 'minimum_bid_interval_ms')
        ->value('COLUMN_DEFAULT');

    expect($default)->toBe('3000');
});

// ------------------------------------------------------------------- Audit

it('records who activated a ruleset, and when', function (): void {
    $admin = userWithRole('admin');
    $draft = AuctionRuleset::factory()->create();

    app(ActivateRuleset::class)->handle($draft, $admin);

    $entry = Activity::where('log_name', 'auction_ruleset')
        ->where('description', 'activated')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->causer_id)->toBe($admin->id)
        ->and($entry?->subject_id)->toBe($draft->id)
        ->and($entry?->created_at)->not->toBeNull();
});

it('records an archival', function (): void {
    $admin = userWithRole('admin');
    $active = AuctionRuleset::factory()->active()->create();

    app(ArchiveRuleset::class)->handle($active, $admin);

    expect(Activity::where('description', 'archived')->where('causer_id', $admin->id)->exists())
        ->toBeTrue();
});

it('records the supersession when a new version takes over', function (): void {
    $admin = userWithRole('admin');
    $v1 = AuctionRuleset::factory()->active()->create(['name' => 'Standard', 'version' => 1]);
    $v2 = AuctionRuleset::factory()->create(['name' => 'Standard', 'version' => 2]);

    app(ActivateRuleset::class)->handle($v2, $admin);

    $entry = Activity::where('description', 'superseded')->where('subject_id', $v1->id)->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->properties->get('superseded_by_id'))->toBe($v2->id);
});

it('records what changed when a draft is edited', function (): void {
    $admin = userWithRole('admin');
    $draft = AuctionRuleset::factory()->create(['minimum_bid_credits' => 1]);

    app(UpdateRuleset::class)->handle($draft, ['minimum_bid_credits' => 4], $admin);

    $entry = Activity::query()
        ->where('subject_type', AuctionRuleset::class)
        ->where('subject_id', $draft->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    // Model-event diffs live in attribute_changes; `properties` holds only
    // data attached manually at the call site.
    expect($entry)->not->toBeNull()
        ->and($entry?->causer_id)->toBe($admin->id)
        ->and(data_get($entry?->attribute_changes, 'attributes.minimum_bid_credits'))->toBe(4)
        ->and(data_get($entry?->attribute_changes, 'old.minimum_bid_credits'))->toBe(1);
});

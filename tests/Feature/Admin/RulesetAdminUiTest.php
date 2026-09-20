<?php

declare(strict_types=1);

use App\Enums\RulesetStatus;
use App\Livewire\Admin\Rulesets\RulesetForm;
use App\Livewire\Admin\Rulesets\RulesetIndex;
use App\Models\AuctionRuleset;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = userWithRole('admin');
});

// -------------------------------------------------------------------- Form

it('creates a draft ruleset from the form', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Weekend Special')
        ->set('minimum_bid_credits', '20')
        ->set('base_duration_seconds', 600)
        ->set('closing_window_seconds', 15)
        ->set('extension_seconds', 15)
        ->set('max_extensions', 10)
        ->set('max_extension_total_seconds', 150)
        ->set('checkout_deadline_minutes', 120)
        ->set('delivery_fee', '25.00')
        ->set('tax_bps', 1000)
        ->call('save')
        ->assertHasNoErrors();

    $ruleset = AuctionRuleset::firstWhere('name', 'Weekend Special');

    expect($ruleset)->not->toBeNull()
        ->and($ruleset?->status)->toBe(RulesetStatus::Draft)
        ->and($ruleset?->minimum_bid_credits)->toBe(20)
        // Entered as "25.00", stored as whole pesewas.
        ->and($ruleset?->delivery_fee_minor)->toBe(2_500)
        ->and($ruleset?->tax_bps)->toBe(1000);
});

it('converts entered amounts into minor units without a float', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Priced')
        ->set('delivery_fee', '0.29')
        ->call('save')
        ->assertHasNoErrors();

    $ruleset = AuctionRuleset::firstWhere('name', 'Priced');

    expect($ruleset?->delivery_fee_minor)->toBe(29);
});

/*
 * An empty bid-rule field means "no rule", not zero. These values have not
 * been decided, and the form must not turn a blank into a number.
 */
it('leaves undecided bid rules unset when the fields are blank', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'No Bid Rules')
        ->set('minimum_bid_credits', '')
        ->set('minimum_bid_increment_credits', '')
        ->set('allow_bid_increase', '')
        ->call('save')
        ->assertHasNoErrors();

    $ruleset = AuctionRuleset::firstWhere('name', 'No Bid Rules');

    expect($ruleset?->minimum_bid_credits)->toBeNull()
        ->and($ruleset?->minimum_bid_increment_credits)->toBeNull()
        ->and($ruleset?->allow_bid_increase)->toBeNull();
});

it('stores a decided bid-increase rule as a real boolean', function (string $choice, bool $expected): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Decided '.$choice)
        ->set('allow_bid_increase', $choice)
        ->call('save')
        ->assertHasNoErrors();

    expect(AuctionRuleset::firstWhere('name', 'Decided '.$choice)?->allow_bid_increase)->toBe($expected);
})->with(['yes' => ['yes', true], 'no' => ['no', false]]);

it('rejects a minimum bid that is not a whole number of credits', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Invalid')
        ->set('minimum_bid_credits', 'not-a-number')
        ->call('save')
        ->assertHasErrors('minimum_bid_credits');

    expect(AuctionRuleset::count())->toBe(0);
});

it('rejects negative values', function (string $field): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Invalid')
        ->set($field, -1)
        ->call('save')
        ->assertHasErrors($field);
})->with([
    'closing_window_seconds',
    'extension_seconds',
    'max_extensions',
    'max_extension_total_seconds',
    'tax_bps',
    'minimum_bid_interval_ms',
]);

it('rejects a zero base duration', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Invalid')
        ->set('base_duration_seconds', 0)
        ->call('save')
        ->assertHasErrors('base_duration_seconds');
});

it('calls the increment field "Bid increment" and no longer "Minimum increment"', function (): void {
    // A label change only: the field, its column and its behaviour are
    // untouched. The wording is the business's, and "minimum" described a
    // lower bound the business intends to replace with an exact step.
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->assertSee('Bid increment (credits)')
        ->assertDontSee('Minimum increment');
});

it('no longer offers a per-credit discount rate field', function (): void {
    // The rate was removed with the lot valuation. The form must not hand an
    // administrator a control for a figure the correction deleted.
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'No Rate')
        ->set('buy_now_credit_discount_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $ruleset = AuctionRuleset::firstWhere('name', 'No Rate');

    expect($ruleset?->buy_now_credit_discount_enabled)->toBeTrue()
        ->and(array_keys($ruleset->toRules()->toArray()))
        ->not->toContain('buy_now_credit_discount_minor_per_credit');
});

it('reports a contradictory configuration as an invariant error', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Contradictory')
        ->set('base_duration_seconds', 60)
        ->set('closing_window_seconds', 120)
        ->call('save')
        ->assertHasErrors('invariants');

    expect(AuctionRuleset::count())->toBe(0);
});

it('edits a draft', function (): void {
    $draft = AuctionRuleset::factory()->create(['minimum_bid_credits' => 10]);

    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class, ['ruleset' => $draft])
        ->assertSet('minimum_bid_credits', '10')
        ->set('minimum_bid_credits', '60')
        ->call('save')
        ->assertHasNoErrors();

    expect($draft->fresh()?->minimum_bid_credits)->toBe(60);
});

it('refuses to open the editor for an active ruleset', function (): void {
    $active = AuctionRuleset::factory()->active()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.rulesets.edit', $active))
        ->assertForbidden();
});

// ------------------------------------------------------------------- Index

it('lists rulesets', function (): void {
    AuctionRuleset::factory()->create(['name' => 'Standard Auction']);

    Livewire::actingAs($this->admin)
        ->test(RulesetIndex::class)
        ->assertOk()
        ->assertSee('Standard Auction');
});

it('filters by status', function (): void {
    AuctionRuleset::factory()->create(['name' => 'A Draft One']);
    AuctionRuleset::factory()->active()->create(['name' => 'An Active One']);

    Livewire::actingAs($this->admin)
        ->test(RulesetIndex::class)
        ->set('status', 'active')
        ->assertSee('An Active One')
        ->assertDontSee('A Draft One');
});

it('activates from the list', function (): void {
    $draft = AuctionRuleset::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(RulesetIndex::class)
        ->call('activate', $draft->id);

    expect($draft->fresh()?->status)->toBe(RulesetStatus::Active);
});

it('archives from the list', function (): void {
    $active = AuctionRuleset::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(RulesetIndex::class)
        ->call('archive', $active->id);

    expect($active->fresh()?->status)->toBe(RulesetStatus::Archived);
});

it('reports a refused archival rather than failing silently', function (): void {
    $default = AuctionRuleset::factory()->default()->create();

    Livewire::actingAs($this->admin)
        ->test(RulesetIndex::class)
        ->call('archive', $default->id)
        ->assertHasErrors('lifecycle');

    expect($default->fresh()?->status)->toBe(RulesetStatus::Active);
});

it('drafts a new version from the list', function (): void {
    $active = AuctionRuleset::factory()->active()->create(['name' => 'Standard', 'version' => 1]);

    Livewire::actingAs($this->admin)
        ->test(RulesetIndex::class)
        ->call('draftNewVersion', $active->id);

    expect(AuctionRuleset::where('name', 'Standard')->where('version', 2)->exists())->toBeTrue();
});

// ----------------------------------------------------------- Authorization

it('forbids a customer from using the form component', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(RulesetForm::class)
        ->assertForbidden();
});

it('forbids a customer from using the index component', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(RulesetIndex::class)
        ->assertForbidden();
});

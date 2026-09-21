<?php

declare(strict_types=1);

use App\Enums\BidModel;
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
        ->set('bid_increment_credits', '2')
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
        ->and($ruleset?->bid_increment_credits)->toBe(2)
        // Chosen in code by the form, never by a field: a new ruleset made here
        // follows the cumulative model, and none of the earlier rule's fields.
        ->and($ruleset?->bid_model)->toBe(BidModel::CumulativeStep)
        ->and($ruleset?->minimum_bid_increment_credits)->toBeNull()
        ->and($ruleset?->allow_bid_increase)->toBeNull()
        // Entered as "25.00", stored as whole pesewas.
        ->and($ruleset?->delivery_fee_minor)->toBe(2_500)
        ->and($ruleset?->tax_bps)->toBe(1000);
});

it('converts entered amounts into minor units without a float', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Priced')
        ->set('minimum_bid_credits', '1')
        ->set('bid_increment_credits', '1')
        ->set('delivery_fee', '0.29')
        ->call('save')
        ->assertHasNoErrors();

    $ruleset = AuctionRuleset::firstWhere('name', 'Priced');

    expect($ruleset?->delivery_fee_minor)->toBe(29);
});

it('refuses a ruleset with no opening bid or no increment, rather than inventing one', function (): void {
    // Under the cumulative model both are required: the minimum bid is the
    // opening bid, the only opening figure that is not invented, and the step is
    // what every later bid is measured by. A blank is refused, never a number
    // nobody chose.
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'No Bid Rules')
        ->set('minimum_bid_credits', '')
        ->set('bid_increment_credits', '')
        ->call('save')
        ->assertHasErrors(['minimum_bid_credits', 'bid_increment_credits']);

    expect(AuctionRuleset::count())->toBe(0);
});

it('refuses an opening bid or an increment of zero', function (string $field): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Zero')
        ->set('minimum_bid_credits', '5')
        ->set('bid_increment_credits', '5')
        ->set($field, '0')
        ->call('save')
        ->assertHasErrors($field);

    expect(AuctionRuleset::count())->toBe(0);
})->with(['minimum_bid_credits', 'bid_increment_credits']);

it('offers no option to raise your own bid, because a leader cannot bid', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->assertDontSee('May a bidder raise their own bid?')
        ->assertDontSee('Minimum increment');

    expect(property_exists(RulesetForm::class, 'allow_bid_increase'))->toBeFalse()
        ->and(property_exists(RulesetForm::class, 'minimum_bid_increment_credits'))->toBeFalse();
});

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
    // "Minimum" described a lower bound. The field on this form is an exact
    // step, and is named for what it is.
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
        ->set('minimum_bid_credits', '1')
        ->set('bid_increment_credits', '1')
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
        ->set('minimum_bid_credits', '1')
        ->set('bid_increment_credits', '1')
        ->set('base_duration_seconds', 60)
        ->set('closing_window_seconds', 120)
        ->call('save')
        ->assertHasErrors('invariants');

    expect(AuctionRuleset::count())->toBe(0);
});

it('edits a draft', function (): void {
    $draft = AuctionRuleset::factory()->cumulative(minimum: 10, increment: 2)->create();

    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class, ['ruleset' => $draft])
        ->assertSet('minimum_bid_credits', '10')
        ->assertSet('bid_increment_credits', '2')
        ->assertDontSee('made under the earlier bidding rule')
        ->set('minimum_bid_credits', '60')
        ->call('save')
        ->assertHasNoErrors();

    expect($draft->fresh()?->minimum_bid_credits)->toBe(60)
        ->and($draft->fresh()?->bid_increment_credits)->toBe(2);
});

it('moves an older draft to the cumulative model when it is saved, and says so', function (): void {
    // Made under the earlier rule, with its lower-bound increment and its
    // raise-your-own-bid option -- both meaningless under the new one.
    $draft = AuctionRuleset::factory()->withBidRules(minimum: 10, increment: 5)
        ->create(['allow_bid_increase' => true]);

    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class, ['ruleset' => $draft])
        ->assertSee('made under the earlier bidding rule')
        // The opening bid carries over. The step is NOT carried over from the
        // old field, whose meaning was different: it is left for somebody to
        // choose.
        ->assertSet('minimum_bid_credits', '10')
        ->assertSet('bid_increment_credits', '')
        ->set('bid_increment_credits', '3')
        ->call('save')
        ->assertHasNoErrors();

    $draft = $draft->fresh();

    expect($draft->bid_model)->toBe(BidModel::CumulativeStep)
        ->and($draft->minimum_bid_credits)->toBe(10)
        ->and($draft->bid_increment_credits)->toBe(3)
        // The earlier rule's fields are cleared, not merely hidden.
        ->and($draft->minimum_bid_increment_credits)->toBeNull()
        ->and($draft->allow_bid_increase)->toBeNull();
});

it('will not save an older draft until the increment has been chosen', function (): void {
    $draft = AuctionRuleset::factory()->withBidRules(minimum: 10, increment: 5)->create();

    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class, ['ruleset' => $draft])
        ->call('save')
        ->assertHasErrors('bid_increment_credits');

    // Untouched: still the earlier rule, still its own increment.
    expect($draft->fresh()->bid_model)->toBe(BidModel::SingleHighest)
        ->and($draft->fresh()->minimum_bid_increment_credits)->toBe(5);
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

it('shows which bidding rule each ruleset follows', function (): void {
    AuctionRuleset::factory()->cumulative(minimum: 5, increment: 2)->create(['name' => 'Newer One']);
    AuctionRuleset::factory()->create(['name' => 'Older One']);

    Livewire::actingAs($this->admin)
        ->test(RulesetIndex::class)
        ->assertSee('Cumulative step')
        ->assertSee('opens at 5')
        ->assertSee('step 2')
        ->assertSee('Single highest bid')
        ->assertSee('earlier rule');
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

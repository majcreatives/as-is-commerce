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
        ->set('bid_cost_credits', 2)
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
        ->and($ruleset?->bid_cost_credits)->toBe(2)
        // Entered as "25.00", stored as whole pesewas.
        ->and($ruleset?->delivery_fee_minor)->toBe(2_500)
        ->and($ruleset?->tax_bps)->toBe(1000);
});

it('converts an entered price into minor units without a float', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Priced')
        ->set('default_checkout_price', '5500.00')
        ->set('delivery_fee', '0.29')
        ->call('save')
        ->assertHasNoErrors();

    $ruleset = AuctionRuleset::firstWhere('name', 'Priced');

    expect($ruleset?->default_checkout_price_minor)->toBe(550_000)
        ->and($ruleset?->delivery_fee_minor)->toBe(29);
});

it('leaves the checkout price unset when the field is blank', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'No Price')
        ->set('default_checkout_price', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(AuctionRuleset::firstWhere('name', 'No Price')?->default_checkout_price_minor)->toBeNull();
});

it('rejects a bid cost below one', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Invalid')
        ->set('bid_cost_credits', 0)
        ->call('save')
        ->assertHasErrors('bid_cost_credits');

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

it('rejects a price containing a currency symbol', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class)
        ->set('name', 'Invalid')
        ->set('default_checkout_price', 'GHS 5500')
        ->call('save')
        ->assertHasErrors('default_checkout_price');
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
    $draft = AuctionRuleset::factory()->create(['bid_cost_credits' => 1]);

    Livewire::actingAs($this->admin)
        ->test(RulesetForm::class, ['ruleset' => $draft])
        ->assertSet('bid_cost_credits', 1)
        ->set('bid_cost_credits', 6)
        ->call('save')
        ->assertHasNoErrors();

    expect($draft->fresh()?->bid_cost_credits)->toBe(6);
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

<?php

declare(strict_types=1);

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\RulesetResolver;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Shared\Money\Money;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;

/*
 * The whole point of the rules engine: an auction is created from a snapshot,
 * not from a live reference to configuration that an administrator can change
 * afterwards. If these tests ever fail, a past auction's recorded behaviour
 * has become unexplainable.
 *
 * No auctions table exists yet, so these prove the property at the level the
 * auction stage will consume -- the value object and its serialized form.
 */

it('produces a complete rules object from a ruleset', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create([
        'bid_cost_credits' => 2,
        'base_duration_seconds' => 600,
        'tax_bps' => 1_500,
    ]);

    $rules = $ruleset->toRules(Money::fromDecimalString('5500.00'));

    expect($rules)->toBeInstanceOf(AuctionRules::class)
        ->and($rules->bidCostCredits)->toBe(2)
        ->and($rules->baseDurationSeconds)->toBe(600)
        ->and($rules->taxBps)->toBe(1_500)
        ->and($rules->checkoutPrice->minor)->toBe(550_000)
        // Provenance travels with the snapshot, so a closed auction can still
        // be traced back to the configuration it ran under.
        ->and($rules->rulesetId)->toBe($ruleset->id)
        ->and($rules->rulesetName)->toBe($ruleset->name)
        ->and($rules->rulesetVersion)->toBe($ruleset->version);
});

it('does not change an existing snapshot when the ruleset is later edited', function (): void {
    $ruleset = AuctionRuleset::factory()->create([
        'bid_cost_credits' => 1,
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 10,
        'extension_seconds' => 10,
        'max_extensions' => 20,
    ]);

    // An auction is created: it takes its snapshot here.
    $snapshot = $ruleset->toRules(Money::fromDecimalString('5500.00'));
    $stored = $snapshot->toArray();

    // An administrator later changes the ruleset substantially.
    $ruleset->update([
        'bid_cost_credits' => 99,
        'base_duration_seconds' => 7_200,
        'closing_window_seconds' => 120,
        'extension_seconds' => 60,
        'max_extensions' => 500,
        'max_extension_total_seconds' => 36_000,
        'tax_bps' => 2_000,
    ]);
    $ruleset->refresh();

    // The live ruleset has moved on.
    expect($ruleset->bid_cost_credits)->toBe(99);

    // The snapshot has not.
    expect($snapshot->bidCostCredits)->toBe(1)
        ->and($snapshot->baseDurationSeconds)->toBe(300)
        ->and($snapshot->closingWindowSeconds)->toBe(10)
        ->and($snapshot->extensionSeconds)->toBe(10)
        ->and($snapshot->maxExtensions)->toBe(20)
        ->and($snapshot->taxBps)->toBe(0);

    // And neither has its serialized form, which is what gets persisted.
    expect($snapshot->toArray())->toBe($stored);

    // Rebuilding from the stored array still yields the original rules.
    expect(AuctionRules::fromArray($stored)->bidCostCredits)->toBe(1);
});

it('does not change an existing snapshot when the ruleset is archived', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['bid_cost_credits' => 4]);

    $snapshot = $ruleset->toRules(Money::fromDecimalString('100.00'));

    $ruleset->update(['bid_cost_credits' => 7]);
    $ruleset->status = RulesetStatus::Archived;
    $ruleset->save();

    expect($snapshot->bidCostCredits)->toBe(4);
});

it('does not change an existing snapshot when the ruleset is deleted outright', function (): void {
    $ruleset = AuctionRuleset::factory()->create(['bid_cost_credits' => 5]);

    $snapshot = $ruleset->toRules(Money::fromDecimalString('100.00'));
    $stored = $snapshot->toArray();

    $ruleset->delete();

    expect($snapshot->bidCostCredits)->toBe(5)
        ->and(AuctionRules::fromArray($stored)->bidCostCredits)->toBe(5);
});

// ------------------------------------------------------------ Checkout price

it('takes the checkout price from the caller, not the ruleset', function (): void {
    $ruleset = AuctionRuleset::factory()->withDefaultCheckoutPrice(100_000)->create();

    $rules = $ruleset->toRules(Money::fromDecimalString('5500.00'));

    expect($rules->checkoutPrice->minor)->toBe(550_000);
});

it('falls back to the ruleset default when no price is supplied', function (): void {
    $ruleset = AuctionRuleset::factory()->withDefaultCheckoutPrice(100_000)->create();

    expect($ruleset->toRules()->checkoutPrice->minor)->toBe(100_000);
});

/*
 * A ruleset with no default price is the normal case: the price belongs to
 * the product. Failing loudly is correct -- inventing a price would be worse.
 */
it('refuses to build rules when neither the caller nor the ruleset supplies a price', function (): void {
    $ruleset = AuctionRuleset::factory()->create(['default_checkout_price_minor' => null]);

    expect(fn (): AuctionRules => $ruleset->toRules())
        ->toThrow(InvalidAuctionRules::class);
});

// ---------------------------------------------------------------- Resolver

it('resolves rules from the default ruleset', function (): void {
    AuctionRuleset::factory()->default()->create(['name' => 'Standard', 'bid_cost_credits' => 3]);

    $rules = app(RulesetResolver::class)->rulesFor(Money::fromDecimalString('250.00'));

    expect($rules->bidCostCredits)->toBe(3)
        ->and($rules->checkoutPrice->minor)->toBe(25_000);
});

it('resolves rules from a named active ruleset', function (): void {
    AuctionRuleset::factory()->default()->create(['name' => 'Standard', 'bid_cost_credits' => 1]);
    AuctionRuleset::factory()->active()->create(['name' => 'Flash', 'bid_cost_credits' => 5]);

    $rules = app(RulesetResolver::class)->rulesFor(Money::fromDecimalString('250.00'), 'Flash');

    expect($rules->bidCostCredits)->toBe(5)
        ->and($rules->rulesetName)->toBe('Flash');
});

it('fails clearly when no default ruleset is active', function (): void {
    expect(fn (): AuctionRules => app(RulesetResolver::class)->rulesFor(Money::fromDecimalString('10.00')))
        ->toThrow(InvalidAuctionRules::class, 'No default auction ruleset is active.');
});

it('does not serve a stale default after the ruleset changes', function (): void {
    $resolver = app(RulesetResolver::class);

    $first = AuctionRuleset::factory()->default()->create(['name' => 'First']);
    expect($resolver->default()?->id)->toBe($first->id);

    // Saving a ruleset invalidates the cached default, so the next read is
    // correct without any caller having to remember to flush.
    $first->is_default = false;
    $first->save();

    $second = AuctionRuleset::factory()->default()->create(['name' => 'Second']);

    expect($resolver->default()?->id)->toBe($second->id);
});

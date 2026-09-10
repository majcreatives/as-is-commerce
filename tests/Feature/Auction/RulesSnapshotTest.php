<?php

declare(strict_types=1);

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\RulesetResolver;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Enums\RulesetStatus;
use App\Models\AuctionRuleset;

/*
 * The whole point of the rules engine: an auction is created from a snapshot,
 * not from a live reference to configuration an administrator can change
 * afterwards. If these tests ever fail, a past auction's recorded behaviour
 * has become unexplainable.
 *
 * No auctions table exists yet, so these prove the property at the level the
 * auction stage will consume -- the value object and its serialized form.
 */

it('produces a complete rules object from a ruleset', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create([
        'minimum_bid_credits' => 20,
        'minimum_bid_increment_credits' => 5,
        'base_duration_seconds' => 600,
        'tax_bps' => 1_500,
    ]);

    $rules = $ruleset->toRules();

    expect($rules)->toBeInstanceOf(AuctionRules::class)
        ->and($rules->minimumBidCredits)->toBe(20)
        ->and($rules->minimumBidIncrementCredits)->toBe(5)
        ->and($rules->baseDurationSeconds)->toBe(600)
        ->and($rules->taxBps)->toBe(1_500)
        ->and($rules->winnerRule())->toBe('highest_valid_credit_bid')
        // Provenance travels with the snapshot, so a closed auction can still
        // be traced back to the configuration it ran under.
        ->and($rules->rulesetId)->toBe($ruleset->id)
        ->and($rules->rulesetName)->toBe($ruleset->name)
        ->and($rules->rulesetVersion)->toBe($ruleset->version);
});

it('does not change an existing snapshot when the ruleset is later edited', function (): void {
    $ruleset = AuctionRuleset::factory()->create([
        'minimum_bid_credits' => 10,
        'minimum_bid_increment_credits' => 5,
        'base_duration_seconds' => 300,
    ]);

    // An auction is created: it takes its snapshot here.
    $snapshot = $ruleset->toRules();
    $stored = $snapshot->toArray();

    // An administrator later changes the ruleset substantially.
    $ruleset->update([
        'minimum_bid_credits' => 999,
        'minimum_bid_increment_credits' => 250,
        'base_duration_seconds' => 7_200,
        'tax_bps' => 2_000,
    ]);
    $ruleset->refresh();

    // The live ruleset has moved on.
    expect($ruleset->minimum_bid_credits)->toBe(999);

    // The snapshot has not.
    expect($snapshot->minimumBidCredits)->toBe(10)
        ->and($snapshot->minimumBidIncrementCredits)->toBe(5)
        ->and($snapshot->baseDurationSeconds)->toBe(300)
        ->and($snapshot->taxBps)->toBe(0);

    // And neither has its serialized form, which is what gets persisted.
    expect($snapshot->toArray())->toBe($stored);

    // Rebuilding from the stored array still yields the original rules.
    expect(AuctionRules::fromArray($stored)->minimumBidCredits)->toBe(10);
});

/*
 * What a credit is worth against Buy Now is no longer a ruleset figure: the
 * Stage 16.5 correction values consumed credits from each lot's own
 * acquisition economics, and that valuation is frozen on the *order's* pricing
 * snapshot at checkout, not on the auction's rules. A rules snapshot carries
 * only the switch, never a rate -- so there is nothing here for an edit to
 * change retroactively.
 */
it('freezes the Buy Now discount switch but no rate into the snapshot', function (): void {
    $ruleset = AuctionRuleset::factory()->create(['buy_now_credit_discount_enabled' => true]);

    $snapshot = $ruleset->toRules();
    $keys = array_keys($snapshot->toArray());

    expect($snapshot->buyNowCreditDiscountEnabled)->toBeTrue()
        ->and($keys)->not->toContain('buy_now_credit_discount_minor_per_credit')
        ->and($keys)->not->toContain('discount_rate');

    $ruleset->update(['buy_now_credit_discount_enabled' => false]);

    // The snapshot still carries the value it was created with.
    expect($snapshot->buyNowCreditDiscountEnabled)->toBeTrue();
});

it('does not change an existing snapshot when the ruleset is archived', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['minimum_bid_credits' => 4]);

    $snapshot = $ruleset->toRules();

    $ruleset->update(['minimum_bid_credits' => 7]);
    $ruleset->status = RulesetStatus::Archived;
    $ruleset->save();

    expect($snapshot->minimumBidCredits)->toBe(4);
});

it('does not change an existing snapshot when the ruleset is deleted outright', function (): void {
    $ruleset = AuctionRuleset::factory()->create(['minimum_bid_credits' => 5]);

    $snapshot = $ruleset->toRules();
    $stored = $snapshot->toArray();

    $ruleset->delete();

    expect($snapshot->minimumBidCredits)->toBe(5)
        ->and(AuctionRules::fromArray($stored)->minimumBidCredits)->toBe(5);
});

// ------------------------------------------------------------ No settlement

/*
 * Snapshots used to carry a checkout price -- the amount a winner paid. What a
 * normal auction winner pays has deliberately not been decided, so the rules
 * carry no such amount and building them requires no price at all.
 */
it('needs no price to produce rules', function (): void {
    $ruleset = AuctionRuleset::factory()->create();

    // No argument, and no error. A ruleset is complete on its own.
    expect($ruleset->toRules())->toBeInstanceOf(AuctionRules::class);
});

it('carries no settlement amount in the snapshot', function (): void {
    $stored = AuctionRuleset::factory()->create()->toRules()->toArray();

    foreach (array_keys($stored) as $key) {
        expect($key)->not->toContain('checkout_price')
            ->and($key)->not->toContain('settlement');
    }
});

// ---------------------------------------------------------------- Resolver

it('resolves rules from the default ruleset', function (): void {
    AuctionRuleset::factory()->default()->create(['name' => 'Standard', 'minimum_bid_credits' => 3]);

    $rules = app(RulesetResolver::class)->rulesFor();

    expect($rules->minimumBidCredits)->toBe(3)
        ->and($rules->winnerRule())->toBe('highest_valid_credit_bid');
});

it('resolves rules from a named active ruleset', function (): void {
    AuctionRuleset::factory()->default()->create(['name' => 'Standard', 'minimum_bid_credits' => 1]);
    AuctionRuleset::factory()->active()->create(['name' => 'Flash', 'minimum_bid_credits' => 50]);

    $rules = app(RulesetResolver::class)->rulesFor('Flash');

    expect($rules->minimumBidCredits)->toBe(50)
        ->and($rules->rulesetName)->toBe('Flash');
});

it('fails clearly when no default ruleset is active', function (): void {
    expect(fn (): AuctionRules => app(RulesetResolver::class)->rulesFor())
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

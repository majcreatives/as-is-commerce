<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\UpdateRuleset;
use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\Exceptions\RulesetNotEditable;
use App\Domain\Auction\RulesetResolver;
use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Shared\Money\Money;
use App\Models\AuctionRuleset;
use App\Models\CreditPackage;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AuctionRulesetSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Guards for the correction itself.
 *
 * The rules engine was originally built for Last Bidder Standing. These tests
 * exist so that model cannot creep back -- not through a column, not through
 * a seeded default, and not through wording that would send a future
 * developer down the wrong path.
 */

// ------------------------------------------------------------ Schema sweep

it('has no last-bidder columns left on the ruleset table', function (string $column): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM auction_rulesets'))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    expect($columns)->not->toContain($column);
})->with([
    // A leader who must not hold the lead twice running is meaningless when
    // the winner is simply whoever bid highest.
    'unique_leader',
    // A fixed cost per bid action cannot express a variable bid amount.
    'bid_cost_credits',
    // A settlement price. What a normal winner pays is undecided.
    'default_checkout_price_minor',
]);

it('has no column whose name implies a last-bidder winner', function (): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM auction_rulesets'))
        ->pluck('Field')
        ->map(fn (string $c): string => strtolower($c))
        ->all();

    foreach (['leader', 'last_bid', 'standing', 'unique'] as $forbidden) {
        expect(collect($columns)->filter(fn (string $c): bool => str_contains($c, $forbidden))->all())
            ->toBe([], "auction_rulesets should have no column containing [{$forbidden}]");
    }
});

it('carries the corrected bid rule columns', function (string $column): void {
    $columns = collect(DB::select('SHOW COLUMNS FROM auction_rulesets'))
        ->pluck('Field')->all();

    expect($columns)->toContain($column);
})->with([
    'minimum_bid_credits',
    'minimum_bid_increment_credits',
    'allow_bid_increase',
    'buy_now_enabled',
    'buy_now_credit_discount_enabled',
]);

// ------------------------------------------------------- The seeded default

it('seeds a default ruleset describing the highest-bid model', function (): void {
    app(AuctionRulesetSeeder::class)->run();

    $ruleset = AuctionRuleset::firstWhere('name', AuctionRulesetSeeder::DEFAULT_NAME);

    expect($ruleset)->not->toBeNull()
        ->and($ruleset->toRules()->winnerRule())->toBe('highest_valid_credit_bid')
        ->and($ruleset->description)->toContain('highest valid credit bid');
});

/*
 * The business has not chosen a minimum bid, an increment, or whether a
 * bidder may raise their own bid. Seeding a number would make that choice by
 * default, and it would be the number everyone then designs around.
 */
it('seeds no invented bid rule values', function (): void {
    app(AuctionRulesetSeeder::class)->run();

    $ruleset = AuctionRuleset::firstWhere('name', AuctionRulesetSeeder::DEFAULT_NAME);

    expect($ruleset->minimum_bid_credits)->toBeNull()
        ->and($ruleset->minimum_bid_increment_credits)->toBeNull()
        ->and($ruleset->allow_bid_increase)->toBeNull();
});

/*
 * Late-bid extension survives the correction but is not switched on by
 * default: whether to use it, and with what window, has not been decided.
 */
it('seeds extensions switched off rather than with chosen numbers', function (): void {
    app(AuctionRulesetSeeder::class)->run();

    $rules = AuctionRuleset::firstWhere('name', AuctionRulesetSeeder::DEFAULT_NAME)->toRules();

    expect($rules->extensionsEnabled())->toBeFalse()
        ->and($rules->maximumPossibleDurationSeconds())->toBe($rules->baseDurationSeconds);
});

it('seeds Buy Now available with the discount switch, and no rate of its own', function (): void {
    app(AuctionRulesetSeeder::class)->run();

    $rules = AuctionRuleset::firstWhere('name', AuctionRulesetSeeder::DEFAULT_NAME)->toRules();

    expect($rules->buyNowEnabled)->toBeTrue()
        ->and($rules->buyNowCreditDiscountEnabled)->toBeTrue()
        // The value is computed from the lot records at checkout, so the
        // ruleset must not be able to express a per-credit rate.
        ->and(array_keys($rules->toArray()))->not->toContain('buy_now_credit_discount_minor_per_credit');
});

// ------------------------------------------------- Variable bid amounts

/*
 * The engine will accept whatever amount a bidder commits. Nothing in the
 * rules caps it at a fixed per-bid cost -- the cap is the bidder's spendable
 * credits, which the wallet decides, not this configuration.
 */
it('constrains bid amounts by rule rather than fixing them', function (): void {
    $rules = AuctionRuleset::factory()
        ->withBidRules(minimum: 20, increment: 10)
        ->create()
        ->toRules();

    // The worked example: 20, then 50, then 100, then 150 are all valid
    // amounts under an increment of 10.
    expect($rules->smallestValidBid())->toBe(20)
        ->and($rules->smallestValidBid(20))->toBe(30)
        ->and($rules->smallestValidBid(50))->toBe(60)
        ->and($rules->smallestValidBid(100))->toBe(110);

    // A bid of 150 clears the floor after a standing bid of 100.
    expect(150)->toBeGreaterThanOrEqual($rules->smallestValidBid(100));
});

/*
 * A bidder who was overtaken and bids higher again is not disqualified. The
 * rules contain nothing that could express such a disqualification.
 */
it('has no rule that would disqualify a bidder for not holding the lead', function (): void {
    $keys = array_keys(AuctionRuleset::factory()->create()->toRules()->toArray());

    foreach ($keys as $key) {
        expect($key)->not->toContain('leader')
            ->and($key)->not->toContain('consecutive');
    }
});

// ------------------------------------------------------------- Separation

it('keeps a product price independent of every auction rule', function (): void {
    $product = Product::factory()->pricedAt(550_000)->create();
    $ruleset = AuctionRuleset::factory()->withBidRules(minimum: 150)->create();

    $ruleset->update(['minimum_bid_credits' => 999, 'tax_bps' => 500]);

    expect($product->fresh()->buy_now_price_minor)->toBe(550_000);
});

it('keeps a credit balance out of the auction rules', function (): void {
    $user = User::factory()->create();
    grantCredits($user, 5_000);

    $rules = AuctionRuleset::factory()->create()->toRules();

    // The rules describe validity, never a balance. The wallet is the only
    // place a balance lives.
    foreach (array_keys($rules->toArray()) as $key) {
        expect($key)->not->toContain('balance')
            ->and($key)->not->toContain('wallet');
    }

    expect(creditWalletFor($user)->fresh()->balance)->toBe(5_000);
});

it('keeps credit package prices unrelated to auction rules', function (): void {
    $package = CreditPackage::factory()->priced(500, 4_500)->create();
    $ruleset = AuctionRuleset::factory()->create();

    $ruleset->update(['tax_bps' => 250]);

    expect($package->fresh()->price_minor)->toBe(4_500);
});

/*
 * The one sanctioned conversion, and the shape of it: consumed bid credits
 * reduce a Buy Now price. It does not give the credits back, and it does not
 * set the product's price -- it computes a discount against it. The value now
 * comes from the lots the bids drew from, never from a rate on the ruleset.
 */
it('converts consumed credits to a discount without touching the product price', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $user = customerWithPurchasedCredits(150, 15_000); // GH 1.00 per credit
    placeBid($auction, $user, 150);

    $discount = app(BuyNowPricer::class)->quote($auction->fresh(), $user)->discount;
    $payable = $product->buyNowPrice()->minus($discount);

    expect($discount->toDecimalString())->toBe('150.00')
        ->and($payable->toDecimalString())->toBe('5350.00')
        // The product itself is untouched: the discount is a calculation, not
        // a repricing.
        ->and($product->fresh()->buy_now_price_minor)->toBe(550_000);
});

// ------------------------------------------------------ Settlement absence

/*
 * The most important guard in this file. What a normal auction winner pays
 * has deliberately not been decided, and the engine must not find an amount
 * here to assume.
 */
it('encodes no settlement amount anywhere in the rules', function (): void {
    $rules = app(RulesetResolver::class);

    AuctionRuleset::factory()->default()->create();

    $snapshot = $rules->rulesFor()->toArray();

    foreach (array_keys($snapshot) as $key) {
        expect($key)->not->toContain('checkout_price')
            ->and($key)->not->toContain('settlement')
            ->and($key)->not->toContain('winner_pays');
    }
});

it('needs no price to resolve rules for an auction', function (): void {
    AuctionRuleset::factory()->default()->create();

    // The resolver takes only an optional ruleset name. There is no parameter
    // through which a settlement price could enter.
    expect(app(RulesetResolver::class)->rulesFor())->toBeInstanceOf(AuctionRules::class);
});

/*
 * A Buy Now price belongs to a product, not to the auction rules, and the two
 * must not be confused into one figure.
 */
it('does not treat a product Buy Now price as an auction settlement price', function (): void {
    Product::factory()->pricedAt(550_000)->create();
    AuctionRuleset::factory()->default()->create();

    $snapshot = app(RulesetResolver::class)->rulesFor()->toArray();

    // Strict comparison: a loose one matches any truthy value, so `true`
    // would register as the price and the test would pass for nothing.
    expect(collect($snapshot)->containsStrict(550_000))->toBeFalse();
});

// ------------------------------------------------------------ Immutability

it('still refuses to mutate an active ruleset after the correction', function (): void {
    $active = AuctionRuleset::factory()->active()->create(['minimum_bid_credits' => 10]);

    expect(fn (): AuctionRuleset => app(UpdateRuleset::class)
        ->handle($active, ['minimum_bid_credits' => 99]))
        ->toThrow(RulesetNotEditable::class);

    expect($active->fresh()->minimum_bid_credits)->toBe(10);
});

it('refuses a snapshot written under the obsolete model', function (): void {
    // Version 1 was the last-bidder shape. Its fields do not mean what this
    // version would read them as, so it is refused rather than reinterpreted.
    $obsolete = [
        'snapshot_version' => 1,
        'bid_cost_credits' => 1,
        'unique_leader' => true,
        'checkout_price_minor' => 550_000,
    ];

    expect(fn (): AuctionRules => AuctionRules::fromArray($obsolete))
        ->toThrow(InvalidAuctionRules::class);
});

it('stores money as integer minor units throughout the rules', function (): void {
    $rules = AuctionRuleset::factory()->create([
        'delivery_fee_minor' => Money::fromDecimalString('25.50')->minor,
    ])->toRules();

    expect($rules->deliveryFee->minor)->toBe(2_550)->toBeInt()
        // The one figure that used to be an integer rate is gone with the lot
        // valuation; everything that remains money is an integer minor count.
        ->and($rules->taxBps)->toBeInt();
});

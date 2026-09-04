<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Exceptions\AuctionConfigurationIsFrozen;
use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\ValueObjects\AuctionSnapshot;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Product;
use Illuminate\Database\QueryException;

/*
 * Creating an auction, and the promise that its terms cannot move afterwards.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->create = app(CreateAuction::class);
    $this->lifecycle = app(AuctionLifecycle::class);
});

// ------------------------------------------------------------- Association

it('is created for a product under a ruleset', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $ruleset = AuctionRuleset::factory()->active()->create(['name' => 'Standard']);

    $auction = $this->create->handle($product, $ruleset, Money::fromMinor(10_000));

    expect($auction->product_id)->toBe($product->id)
        ->and($auction->auction_ruleset_id)->toBe($ruleset->id)
        ->and($auction->status)->toBe(AuctionStatus::Draft)
        // A draft holds no stock: publishing is a separate, deliberate act.
        ->and($product->fresh()->stock_reserved)->toBe(0);
});

it('records its creation in the transition history', function (): void {
    $auction = $this->create->handle(
        Product::factory()->active()->create(),
        AuctionRuleset::factory()->active()->create(['name' => 'Standard', 'version' => 3]),
        Money::fromMinor(5_000),
    );

    $transition = $auction->transitions()->first();

    expect($transition?->from_status)->toBeNull()
        ->and($transition?->to_status)->toBe(AuctionStatus::Draft)
        ->and($transition?->reason)->toContain('Standard v3');
});

it('refuses to auction an archived product', function (): void {
    expect(fn (): Auction => $this->create->handle(
        Product::factory()->archived()->create(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    ))->toThrow(InvalidAuctionRules::class, 'archived product');
});

// ---------------------------------------------------------------- Snapshot

it('freezes the complete ruleset into the auction', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create([
        'minimum_bid_credits' => 20,
        'minimum_bid_increment_credits' => 10,
        'base_duration_seconds' => 600,
        'tax_bps' => 1_500,
    ]);

    $auction = $this->create->handle(
        Product::factory()->active()->create(),
        $ruleset,
        Money::fromMinor(10_000),
    );

    $rules = $auction->rules();

    expect($rules->minimumBidCredits)->toBe(20)
        ->and($rules->minimumBidIncrementCredits)->toBe(10)
        ->and($rules->baseDurationSeconds)->toBe(600)
        ->and($rules->taxBps)->toBe(1_500)
        // Provenance travels with it, so a closed auction can still be traced
        // to the configuration it ran under.
        ->and($rules->rulesetId)->toBe($ruleset->id)
        ->and($rules->rulesetVersion)->toBe($ruleset->version);
});

it('records the winner rule in its own snapshot', function (): void {
    $auction = $this->create->handle(
        Product::factory()->active()->create(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    // Read from the auction's frozen record rather than inferred from what the
    // code happens to do this week.
    expect($auction->winnerRule())->toBe('highest_valid_credit_bid')
        ->and($auction->rules_snapshot['rules']['winner_rule'])->toBe('highest_valid_credit_bid');
});

it('does not follow the ruleset when it is edited afterwards', function (): void {
    $ruleset = AuctionRuleset::factory()->create(['minimum_bid_credits' => 20]);

    $auction = $this->create->handle(
        Product::factory()->active()->create(),
        $ruleset,
        Money::fromMinor(10_000),
    );

    $ruleset->update(['minimum_bid_credits' => 999, 'base_duration_seconds' => 99_999]);

    expect($ruleset->fresh()->minimum_bid_credits)->toBe(999)
        ->and($auction->fresh()->rules()->minimumBidCredits)->toBe(20);
});

it('survives the ruleset being archived or deleted outright', function (): void {
    $ruleset = AuctionRuleset::factory()->create(['minimum_bid_credits' => 7]);

    $auction = $this->create->handle(
        Product::factory()->active()->create(),
        $ruleset,
        Money::fromMinor(10_000),
    );

    $ruleset->delete();

    $reloaded = Auction::findOrFail($auction->id);

    expect($reloaded->auction_ruleset_id)->toBeNull()
        ->and($reloaded->rules()->minimumBidCredits)->toBe(7)
        ->and($reloaded->winnerRule())->toBe('highest_valid_credit_bid');
});

// ------------------------------------------------------- Settlement amount

it('freezes the settlement amount into the snapshot', function (): void {
    $auction = $this->create->handle(
        Product::factory()->active()->create(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    expect($auction->settlement_amount_minor)->toBe(10_000)
        ->and($auction->settlementAmount()->toDecimalString())->toBe('100.00')
        ->and($auction->snapshot()->settlementAmount->minor)->toBe(10_000);
});

/*
 * The heart of it. Buy Now and settlement are two independent numbers, and an
 * auction may settle at any figure the business chooses regardless of what the
 * product sells for.
 */
it('keeps the settlement amount independent of the Buy Now price', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $ruleset = AuctionRuleset::factory()->active()->create();

    $cheap = $this->create->handle($product, $ruleset, Money::fromMinor(5_000));
    $dearer = $this->create->handle($product, $ruleset, Money::fromMinor(15_000));

    expect($product->fresh()->buy_now_price_minor)->toBe(550_000)
        ->and($cheap->settlement_amount_minor)->toBe(5_000)
        ->and($dearer->settlement_amount_minor)->toBe(15_000)
        // Two auctions, one product, deliberately different economics.
        ->and($cheap->settlement_amount_minor)->not->toBe($dearer->settlement_amount_minor);
});

it('never derives the settlement amount from the product price', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();

    $auction = $this->create->handle(
        $product,
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    // Strict, because a loose comparison matches any truthy value and would
    // pass for entirely the wrong reason.
    expect($auction->settlement_amount_minor)->not->toBe(550_000)
        ->and(collect($auction->rules_snapshot['auction'])->containsStrict(550_000))->toBeFalse();
});

it('refuses a settlement amount of nothing', function (): void {
    expect(fn (): Auction => $this->create->handle(
        Product::factory()->active()->create(),
        AuctionRuleset::factory()->active()->create(),
        Money::zero(),
    ))->toThrow(InvalidAuctionRules::class, 'greater than zero');
});

it('refuses a settlement amount in a different currency from the rules', function (): void {
    expect(fn (): Auction => $this->create->handle(
        Product::factory()->active()->create(),
        AuctionRuleset::factory()->active()->create(['currency' => 'GHS']),
        Money::fromMinor(10_000, 'USD'),
    ))->toThrow(InvalidAuctionRules::class);
});

it('stores the settlement amount as integer minor units', function (): void {
    $auction = $this->create->handle(
        Product::factory()->active()->create(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromDecimalString('100.55'),
    );

    expect($auction->settlement_amount_minor)->toBe(10_055)->toBeInt()
        ->and($auction->settlementAmount()->toDecimalString())->toBe('100.55');
});

// ------------------------------------------------------------ Immutability

it('allows a draft to be corrected', function (): void {
    $auction = Auction::factory()->create();

    $auction->settlement_amount_minor = 20_000;
    $auction->save();

    expect($auction->fresh()->settlement_amount_minor)->toBe(20_000);
});

it('refuses to change the frozen configuration once published', function (string $column, mixed $value): void {
    $auction = liveAuction();

    $auction->{$column} = $value;

    expect(fn (): bool => $auction->save())
        ->toThrow(AuctionConfigurationIsFrozen::class);
})->with([
    'settlement amount' => ['settlement_amount_minor', 99_999],
    'product' => ['product_id', 999],
    'currency' => ['currency', 'USD'],
    'snapshot version' => ['snapshot_version', 99],
]);

it('refuses to rewrite the rules snapshot once published', function (): void {
    $auction = liveAuction();

    $tampered = $auction->rules_snapshot;
    $tampered['rules']['minimum_bid_credits'] = 1;
    $auction->rules_snapshot = $tampered;

    expect(fn (): bool => $auction->save())
        ->toThrow(AuctionConfigurationIsFrozen::class);
});

/*
 * The application guard can be bypassed by a console command or a hand-run
 * statement. The database cannot.
 */
it('refuses a frozen change made straight through the database', function (): void {
    $auction = liveAuction();

    expect(fn () => DB::table('auctions')
        ->where('id', $auction->id)
        ->update(['settlement_amount_minor' => 1]))
        ->toThrow(QueryException::class, 'frozen');

    expect($auction->fresh()->settlement_amount_minor)->toBe(10_000);
});

it('still allows lifecycle columns to change after publication', function (): void {
    $auction = liveAuction();

    // Freezing configuration must not freeze the auction itself: the clock,
    // the status and the winner all have to keep moving.
    $this->lifecycle->cancel($auction, 'Testing.');

    expect($auction->fresh()->status)->toBe(AuctionStatus::Cancelled);
});

// -------------------------------------------------------- Snapshot version

it('refuses a snapshot written under an unknown version', function (): void {
    expect(fn (): AuctionSnapshot => AuctionSnapshot::fromArray([
        'snapshot_version' => 99,
        'rules' => [],
        'auction' => ['settlement_amount_minor' => 10_000, 'currency' => 'GHS'],
    ]))->toThrow(InvalidAuctionRules::class, 'not supported');
});

it('round trips a snapshot without losing anything', function (): void {
    $auction = liveAuction();

    $stored = $auction->rules_snapshot;
    $rebuilt = AuctionSnapshot::fromArray($stored);

    // Compared by content rather than identically: MySQL normalises the key
    // order of a JSON object on storage, so what comes back is the same
    // snapshot written in a different order. Every key and value survives,
    // which is what actually matters.
    expect($rebuilt->toArray())->toEqual($stored)
        ->and($rebuilt->settlementAmount->minor)->toBe(10_000)
        ->and($rebuilt->rules->rulesetId)->toBe($auction->auction_ruleset_id);
});

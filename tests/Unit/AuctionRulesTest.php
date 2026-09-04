<?php

declare(strict_types=1);

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Shared\Money\Money;
use App\Enums\ForfeitPolicy;

/**
 * @param  array<string, mixed>  $overrides
 */
function rules(array $overrides = []): AuctionRules
{
    return new AuctionRules(...array_merge([
        'minimumBidCredits' => null,
        'minimumBidIncrementCredits' => null,
        'allowBidIncrease' => null,
        'minimumBidIntervalMs' => 1000,
        'baseDurationSeconds' => 300,
        'closingWindowSeconds' => 0,
        'extensionSeconds' => 0,
        'maxExtensions' => 0,
        'maxExtensionTotalSeconds' => 0,
        'buyNowEnabled' => true,
        'buyNowCreditDiscountEnabled' => true,
        'buyNowCreditDiscountMinorPerCredit' => 100,
        'checkoutDeadlineMinutes' => 60,
        'forfeitPolicy' => ForfeitPolicy::Relist,
        'deliveryFee' => Money::zero(),
        'taxBps' => 0,
    ], $overrides));
}

// ------------------------------------------------------------- Winner rule

/*
 * The correction this stage exists for. The winner is whoever holds the
 * highest valid credit bid at normal closure -- not the last bidder, which is
 * what the rules were originally built for.
 */
it('declares that the highest valid credit bid wins', function (): void {
    expect(AuctionRules::WINNER_RULE)->toBe('highest_valid_credit_bid')
        ->and(rules()->winnerRule())->toBe('highest_valid_credit_bid');
});

it('records the winner rule in every snapshot', function (): void {
    expect(rules()->toArray()['winner_rule'])->toBe('highest_valid_credit_bid');
});

/*
 * A future engine reading these rules must find nothing suggesting the last
 * bidder, a continuously-held lead, or a fixed cost per bid.
 */
it('carries no trace of the obsolete last-bidder model', function (): void {
    $keys = array_keys(rules()->toArray());

    expect($keys)->not->toContain('unique_leader')
        ->and($keys)->not->toContain('bid_cost_credits')
        ->and($keys)->not->toContain('last_bidder');

    foreach ($keys as $key) {
        expect($key)->not->toContain('leader')
            ->and($key)->not->toContain('last_bid');
    }
});

/*
 * What a normal auction winner pays has deliberately not been decided.
 * Encoding an amount here would be inventing that decision.
 */
it('carries no settlement price', function (): void {
    $keys = array_keys(rules()->toArray());

    expect($keys)->not->toContain('checkout_price_minor')
        ->and($keys)->not->toContain('settlement_price_minor')
        ->and(property_exists(AuctionRules::class, 'checkoutPrice'))->toBeFalse();
});

// -------------------------------------------------------------- Bid rules

/*
 * Bids carry their own amounts. Nothing here assumes one credit per bid, or
 * any fixed cost per bid action.
 */
it('leaves bid rules unset when the business has not decided them', function (): void {
    $rules = rules();

    expect($rules->minimumBidCredits)->toBeNull()
        ->and($rules->minimumBidIncrementCredits)->toBeNull()
        ->and($rules->allowBidIncrease)->toBeNull()
        ->and($rules->hasMinimumBid())->toBeFalse()
        ->and($rules->hasMinimumIncrement())->toBeFalse();
});

it('imposes no floor when no bid rule is configured', function (): void {
    // Null, not one. An unset rule is not a rule of one, and an engine that
    // invented a floor would be making a decision nobody made.
    expect(rules()->smallestValidBid())->toBeNull()
        ->and(rules()->smallestValidBid(500))->toBeNull();
});

it('represents a minimum bid when one is configured', function (): void {
    $rules = rules(['minimumBidCredits' => 25]);

    expect($rules->hasMinimumBid())->toBeTrue()
        ->and($rules->smallestValidBid())->toBe(25);
});

it('represents a minimum increment above the standing highest bid', function (): void {
    $rules = rules(['minimumBidIncrementCredits' => 10]);

    expect($rules->hasMinimumIncrement())->toBeTrue()
        ->and($rules->smallestValidBid(100))->toBe(110)
        // With no standing bid there is nothing to increment above.
        ->and($rules->smallestValidBid())->toBeNull();
});

it('takes the higher of the minimum and the increment', function (): void {
    $rules = rules(['minimumBidCredits' => 50, 'minimumBidIncrementCredits' => 10]);

    // Early on, the absolute minimum governs.
    expect($rules->smallestValidBid(5))->toBe(50)
        // Later, the increment does.
        ->and($rules->smallestValidBid(200))->toBe(210);
});

it('supports variable bid amounts of any size', function (int $amount): void {
    $rules = rules(['minimumBidCredits' => 1]);

    // Nothing caps a bid at a fixed per-bid cost; the engine will cap it
    // against the bidder's spendable credits instead.
    expect($amount)->toBeGreaterThanOrEqual($rules->smallestValidBid());
})->with([1, 20, 50, 100, 150, 5000]);

it('rejects a minimum bid below one credit', function (): void {
    expect(fn (): AuctionRules => rules(['minimumBidCredits' => 0]))
        ->toThrow(InvalidAuctionRules::class);
});

it('rejects a minimum increment below one credit', function (): void {
    expect(fn (): AuctionRules => rules(['minimumBidIncrementCredits' => 0]))
        ->toThrow(InvalidAuctionRules::class);
});

// ---------------------------------------------------------------- Buy Now

it('represents whether Buy Now is available', function (): void {
    expect(rules()->buyNowEnabled)->toBeTrue()
        ->and(rules([
            'buyNowEnabled' => false,
            'buyNowCreditDiscountEnabled' => false,
        ])->buyNowEnabled)->toBeFalse();
});

/*
 * One consumed bid credit takes GH 1 off the Buy Now price. Stored as an
 * explicit rate in minor units rather than assumed in code, so it is versioned
 * with everything else.
 */
it('represents one credit as one cedi of Buy Now discount', function (): void {
    $rules = rules();

    expect($rules->buyNowCreditDiscountMinorPerCredit)->toBe(100)
        ->and($rules->buyNowDiscountFor(1)->toDecimalString())->toBe('1.00')
        ->and($rules->buyNowDiscountFor(150)->toDecimalString())->toBe('150.00');
});

it('calculates the worked example from the specification', function (): void {
    // Product at GH 5,500 with 150 credits consumed: GH 150 off, GH 5,350 due.
    $price = Money::fromDecimalString('5500.00');
    $discount = rules()->buyNowDiscountFor(150);

    expect($discount->toDecimalString())->toBe('150.00')
        ->and($price->minus($discount)->toDecimalString())->toBe('5350.00');
});

it('gives no discount when the discount is switched off', function (): void {
    $rules = rules(['buyNowCreditDiscountEnabled' => false]);

    expect($rules->buyNowDiscountFor(150)->isZero())->toBeTrue();
});

it('gives no discount for a non-positive number of credits', function (int $credits): void {
    expect(rules()->buyNowDiscountFor($credits)->isZero())->toBeTrue();
})->with([0, -1]);

/*
 * A discount on a Buy Now that cannot happen is a contradiction, not a
 * harmless setting.
 */
it('refuses a credit discount while Buy Now is disabled', function (): void {
    expect(fn (): AuctionRules => rules([
        'buyNowEnabled' => false,
        'buyNowCreditDiscountEnabled' => true,
    ]))->toThrow(InvalidAuctionRules::class);
});

it('rejects a discount rate of zero', function (): void {
    expect(fn (): AuctionRules => rules(['buyNowCreditDiscountMinorPerCredit' => 0]))
        ->toThrow(InvalidAuctionRules::class);
});

// ---------------------------------------------------------------- Timing

/*
 * Late-bid extension survives the correction, because anti-sniping is still
 * useful. What changed is that it no longer decides anything about the winner.
 */
it('treats extensions as optional', function (): void {
    expect(rules()->extensionsEnabled())->toBeFalse()
        ->and(rules()->maximumPossibleDurationSeconds())->toBe(300);
});

it('supports late-bid extension when configured', function (): void {
    $rules = rules([
        'closingWindowSeconds' => 10,
        'extensionSeconds' => 10,
        'maxExtensions' => 20,
        'maxExtensionTotalSeconds' => 300,
    ]);

    expect($rules->extensionsEnabled())->toBeTrue()
        ->and($rules->maximumPossibleDurationSeconds())->toBe(500);
});

it('keeps extension entirely separate from who wins', function (): void {
    $extending = rules([
        'closingWindowSeconds' => 10,
        'extensionSeconds' => 10,
        'maxExtensions' => 5,
        'maxExtensionTotalSeconds' => 50,
    ]);

    // Extending the clock changes how long bidding lasts, never the rule by
    // which the winner is chosen.
    expect($extending->winnerRule())->toBe(rules()->winnerRule());
});

it('rejects a closing window longer than the auction', function (): void {
    expect(fn (): AuctionRules => rules([
        'baseDurationSeconds' => 60,
        'closingWindowSeconds' => 120,
    ]))->toThrow(InvalidAuctionRules::class);
});

it('rejects extensions that could never trigger', function (): void {
    expect(fn (): AuctionRules => rules([
        'closingWindowSeconds' => 0,
        'extensionSeconds' => 10,
        'maxExtensions' => 5,
        'maxExtensionTotalSeconds' => 50,
    ]))->toThrow(InvalidAuctionRules::class);
});

it('rejects an extension budget shorter than a single extension', function (): void {
    expect(fn (): AuctionRules => rules([
        'closingWindowSeconds' => 10,
        'extensionSeconds' => 30,
        'maxExtensions' => 5,
        'maxExtensionTotalSeconds' => 10,
    ]))->toThrow(InvalidAuctionRules::class);
});

// ------------------------------------------------------------- Validation

it('rejects a non-positive base duration', function (int $duration): void {
    expect(fn (): AuctionRules => rules(['baseDurationSeconds' => $duration]))
        ->toThrow(InvalidAuctionRules::class);
})->with([0, -1]);

it('rejects negative values', function (string $field): void {
    expect(fn (): AuctionRules => rules([$field => -1]))
        ->toThrow(InvalidAuctionRules::class);
})->with([
    'minimumBidIntervalMs',
    'closingWindowSeconds',
    'extensionSeconds',
    'maxExtensions',
    'maxExtensionTotalSeconds',
    'taxBps',
]);

it('rejects a non-positive checkout deadline', function (): void {
    expect(fn (): AuctionRules => rules(['checkoutDeadlineMinutes' => 0]))
        ->toThrow(InvalidAuctionRules::class);
});

it('rejects tax above one hundred percent', function (): void {
    expect(fn (): AuctionRules => rules(['taxBps' => 10_001]))
        ->toThrow(InvalidAuctionRules::class);
});

// --------------------------------------------------------------- Snapshot

it('survives a round trip through its serialized form', function (): void {
    $original = rules([
        'minimumBidCredits' => 25,
        'minimumBidIncrementCredits' => 5,
        'allowBidIncrease' => true,
        'taxBps' => 1_250,
        'deliveryFee' => Money::fromDecimalString('25.50'),
        'forfeitPolicy' => ForfeitPolicy::OfferRunnerUp,
    ]);

    $restored = AuctionRules::fromArray($original->toArray());

    expect($restored->toArray())->toBe($original->toArray())
        ->and($restored->minimumBidCredits)->toBe(25)
        ->and($restored->minimumBidIncrementCredits)->toBe(5)
        ->and($restored->allowBidIncrease)->toBeTrue()
        ->and($restored->deliveryFee->minor)->toBe(2_550);
});

it('preserves an undecided bid rule as undecided through a round trip', function (): void {
    // Null must survive as null. Coercing it to false or zero would silently
    // turn "not decided" into a decision.
    $restored = AuctionRules::fromArray(rules()->toArray());

    expect($restored->minimumBidCredits)->toBeNull()
        ->and($restored->minimumBidIncrementCredits)->toBeNull()
        ->and($restored->allowBidIncrease)->toBeNull();
});

it('survives a round trip through JSON, which is how it will be stored', function (): void {
    $original = rules(['minimumBidCredits' => 10]);

    $json = json_encode($original);
    expect($json)->toBeString();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $json, true);

    expect(AuctionRules::fromArray($decoded)->toArray())->toBe($original->toArray());
});

it('refuses a snapshot written by an incompatible version', function (int $version): void {
    $data = rules()->toArray();
    $data['snapshot_version'] = $version;

    expect(fn (): AuctionRules => AuctionRules::fromArray($data))
        ->toThrow(InvalidAuctionRules::class);
})->with([
    // Version 1 was the obsolete last-bidder shape. Refusing it is correct:
    // its fields do not mean what this version would read them as.
    'the obsolete model' => 1,
    'a future model' => 999,
]);

it('is at snapshot version two', function (): void {
    expect(AuctionRules::SNAPSHOT_VERSION)->toBe(2)
        ->and(rules()->toArray()['snapshot_version'])->toBe(2);
});

it('cannot be mutated after construction', function (): void {
    $rules = rules();

    expect(fn () => $rules->minimumBidCredits = 99)->toThrow(Error::class);
});

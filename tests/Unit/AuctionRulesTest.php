<?php

declare(strict_types=1);

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Domain\Shared\Money\Money;
use App\Enums\BidModel;
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
        'minimumBidIntervalMs' => 3000,
        'baseDurationSeconds' => 300,
        'closingWindowSeconds' => 0,
        'extensionSeconds' => 0,
        'maxExtensions' => 0,
        'maxExtensionTotalSeconds' => 0,
        'buyNowEnabled' => true,
        'buyNowCreditDiscountEnabled' => true,
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
 * The credit discount is now valued from each lot's own acquisition economics
 * by the pricer -- never from a system-wide rate, which the Stage 16.5
 * correction removed. A ruleset carrying a rate, or claiming to compute a
 * discount in credits, would reintroduce the very figure that was deleted.
 */
it('carries no discount rate and no discount arithmetic', function (): void {
    $keys = array_keys(rules()->toArray());

    expect($keys)->not->toContain('buy_now_credit_discount_minor_per_credit')
        ->and($keys)->not->toContain('discount_rate')
        ->and($keys)->not->toContain('discount_minor_per_credit');

    foreach ($keys as $key) {
        expect($key)->not->toContain('per_credit')
            ->and($key)->not->toContain('discount_rate');
    }
});

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
    // Version 3 had no bid model. It is refused rather than read as one: the
    // migration that rewrites stored snapshots is what upgrades them.
    'the previous version' => 3,
    'a future model' => 999,
]);

it('is at snapshot version four', function (): void {
    // Version 3 was the lot-valued shape: the flat per-credit rate was removed,
    // because what a consumed credit is worth comes from the lots it was bought
    // in, never from the ruleset. Version 4 adds the bid model, so the winner
    // rule is read from the snapshot instead of being a constant of the code.
    expect(AuctionRules::SNAPSHOT_VERSION)->toBe(4)
        ->and(rules()->toArray()['snapshot_version'])->toBe(4)
        ->and(rules()->toArray())->not->toHaveKey('buy_now_credit_discount_minor_per_credit');
});

// --------------------------------------------------------------- Bid model

it('follows the single-highest model unless told otherwise', function (): void {
    expect(rules()->bidModel)->toBe(BidModel::SingleHighest)
        ->and(rules()->winnerRule())->toBe('highest_valid_credit_bid');
});

it('records the bid model and the winner rule it implies', function (): void {
    $array = rules()->toArray();

    expect($array['bid_model'])->toBe('single_highest')
        ->and($array['winner_rule'])->toBe('highest_valid_credit_bid');
});

it('reads its winner rule from the model, not from a constant', function (): void {
    // The point of version 4. The winner rule used to be a constant that
    // fromArray() ignored, so a snapshot could record one and the engine could
    // never honour another. It is now derived from what the snapshot says.
    $rules = AuctionRules::fromArray(rules()->toArray());

    expect($rules->bidModel->winnerRule())->toBe($rules->winnerRule());
});

it('refuses a snapshot that names a bid model this engine cannot honour', function (): void {
    // A model this engine has no rule for. Ranking such an auction as
    // something it is not would name the wrong winner without any error, so it
    // is refused loudly.
    $data = rules()->toArray();
    $data['bid_model'] = 'auto_bid';

    expect(fn (): AuctionRules => AuctionRules::fromArray($data))
        ->toThrow(InvalidAuctionRules::class, 'cannot honour');
});

it('refuses a snapshot with no bid model', function (): void {
    $data = rules()->toArray();
    unset($data['bid_model']);

    expect(fn (): AuctionRules => AuctionRules::fromArray($data))
        ->toThrow(InvalidAuctionRules::class);
});

it('refuses a snapshot whose winner rule disagrees with its bid model', function (): void {
    // Which half to believe is exactly the guess this refuses to make.
    $data = rules()->toArray();
    $data['winner_rule'] = 'highest_cumulative_credits';

    expect(fn (): AuctionRules => AuctionRules::fromArray($data))
        ->toThrow(InvalidAuctionRules::class, 'disagree');
});

it('cannot be mutated after construction', function (): void {
    $rules = rules();

    expect(fn () => $rules->minimumBidCredits = 99)->toThrow(Error::class);
});

// ------------------------------------------------- The cumulative model

/**
 * Rules under the cumulative model, with the fields it needs.
 *
 * @param  array<string, mixed>  $overrides
 */
function cumulativeRules(array $overrides = []): AuctionRules
{
    return rules(array_merge([
        'bidModel' => BidModel::CumulativeStep,
        'minimumBidCredits' => 1,
        'bidIncrementCredits' => 1,
    ], $overrides));
}

it('derives the cumulative winner rule from the model', function (): void {
    expect(cumulativeRules()->winnerRule())->toBe('highest_cumulative_credits')
        ->and(cumulativeRules()->toArray()['winner_rule'])->toBe('highest_cumulative_credits')
        ->and(cumulativeRules()->toArray()['bid_model'])->toBe('cumulative_step');
});

it('round-trips a cumulative snapshot with its step', function (): void {
    $original = cumulativeRules(['minimumBidCredits' => 5, 'bidIncrementCredits' => 2]);

    $back = AuctionRules::fromArray($original->toArray());

    expect($back->toArray())->toBe($original->toArray())
        ->and($back->bidModel)->toBe(BidModel::CumulativeStep)
        ->and($back->bidIncrementCredits)->toBe(2);
});

it('reads a version 4 snapshot written before the step existed', function (): void {
    // Every single-highest snapshot on staging was written without this key.
    // It reads as null, which is exactly what it is.
    $data = rules()->toArray();
    unset($data['bid_increment_credits']);

    expect(AuctionRules::fromArray($data)->bidIncrementCredits)->toBeNull();
});

it('computes the catch-up bid as leader plus step minus your own total', function (int $leader, int $mine, int $step, int $expected): void {
    expect(cumulativeRules(['bidIncrementCredits' => $step])->catchUpBid($leader, $mine))->toBe($expected);
})->with([
    'B joins behind A on 1' => [1, 0, 1, 2],
    'A retakes the lead from B' => [2, 1, 1, 2],
    'C joins behind A on 3' => [3, 0, 1, 4],
    'B retakes the lead from C' => [4, 2, 1, 3],
    'a step of two, from nothing' => [5, 0, 2, 7],
    'a step of two, catching up' => [7, 5, 2, 4],
    'a newcomer pays the whole standing plus one step' => [100, 0, 1, 101],
]);

it('opens with the minimum bid when nobody has bid', function (): void {
    expect(cumulativeRules(['minimumBidCredits' => 5])->catchUpBid(null))->toBe(5);
});

it('refuses to invent a catch-up bid for somebody who is not below the leader', function (): void {
    // A non-leader is always below the leader. A total at or above it means the
    // records disagree with the rule, which is reported, not turned into a bid.
    expect(fn () => cumulativeRules()->catchUpBid(5, 5))->toThrow(LogicException::class);
    expect(fn () => cumulativeRules()->catchUpBid(5, 9))->toThrow(LogicException::class);
});

it('has no catch-up bid on the single-highest model', function (): void {
    expect(fn () => rules()->catchUpBid(5, 0))->toThrow(InvalidAuctionRules::class);
});

it('has no smallest valid bid under the cumulative model, only one valid bid', function (): void {
    expect(cumulativeRules()->smallestValidBid(20))->toBeNull();
});

it('refuses cumulative rules that are incomplete or that mix in the other model\'s fields', function (array $overrides, string $message): void {
    expect(fn () => cumulativeRules($overrides))
        ->toThrow(InvalidAuctionRules::class, $message);
})->with([
    'no opening bid' => [['minimumBidCredits' => null], 'needs a minimum bid'],
    'no step' => [['bidIncrementCredits' => null], 'needs a bid increment'],
    'a step of zero' => [['bidIncrementCredits' => 0], 'at least 1 credit'],
    'a lower-bound increment beside a step' => [['minimumBidIncrementCredits' => 5], 'not a minimum increment'],
    'raising your own bid, where a leader cannot bid' => [['allowBidIncrease' => true], 'no meaning under the cumulative model'],
]);

it('refuses a step on a single-highest ruleset', function (): void {
    expect(fn () => rules(['bidIncrementCredits' => 2]))
        ->toThrow(InvalidAuctionRules::class, 'belongs to the cumulative model');
});

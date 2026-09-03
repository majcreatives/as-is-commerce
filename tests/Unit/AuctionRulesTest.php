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
        'bidCostCredits' => 1,
        'uniqueLeader' => true,
        'minimumBidIntervalMs' => 1000,
        'baseDurationSeconds' => 300,
        'closingWindowSeconds' => 10,
        'extensionSeconds' => 10,
        'maxExtensions' => 20,
        'maxExtensionTotalSeconds' => 300,
        'checkoutDeadlineMinutes' => 60,
        'forfeitPolicy' => ForfeitPolicy::Relist,
        'checkoutPrice' => Money::fromDecimalString('5500.00'),
        'deliveryFee' => Money::zero(),
        'taxBps' => 0,
    ], $overrides));
}

it('exposes every rule the auction engine will need', function (): void {
    $rules = rules();

    expect($rules->bidCostCredits)->toBe(1)
        ->and($rules->uniqueLeader)->toBeTrue()
        ->and($rules->minimumBidIntervalMs)->toBe(1000)
        ->and($rules->baseDurationSeconds)->toBe(300)
        ->and($rules->closingWindowSeconds)->toBe(10)
        ->and($rules->extensionSeconds)->toBe(10)
        ->and($rules->maxExtensions)->toBe(20)
        ->and($rules->maxExtensionTotalSeconds)->toBe(300)
        ->and($rules->checkoutDeadlineMinutes)->toBe(60)
        ->and($rules->forfeitPolicy)->toBe(ForfeitPolicy::Relist)
        ->and($rules->checkoutPrice->minor)->toBe(550_000)
        ->and($rules->deliveryFee->minor)->toBe(0)
        ->and($rules->taxBps)->toBe(0)
        ->and($rules->currency())->toBe('GHS');
});

/*
 * The credits spent bidding and the price the winner pays are separate
 * concepts. Nothing in the rules object may couple them.
 */
it('keeps bid cost and checkout price independent', function (): void {
    $rules = rules(['bidCostCredits' => 15, 'checkoutPrice' => Money::fromDecimalString('5500.00')]);

    expect($rules->bidCostCredits)->toBe(15)
        ->and($rules->checkoutPrice->minor)->toBe(550_000);
});

// ------------------------------------------------------------ Single field

it('rejects a bid cost below one credit', function (int $cost): void {
    expect(fn (): AuctionRules => rules(['bidCostCredits' => $cost]))
        ->toThrow(InvalidAuctionRules::class);
})->with([0, -1, -10]);

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

it('rejects a checkout price of zero or less', function (int $minor): void {
    expect(fn (): AuctionRules => rules(['checkoutPrice' => Money::fromMinor($minor)]))
        ->toThrow(InvalidAuctionRules::class);
})->with([0, -1]);

it('rejects tax above one hundred percent', function (): void {
    expect(fn (): AuctionRules => rules(['taxBps' => 10_001]))
        ->toThrow(InvalidAuctionRules::class);
});

it('rejects a delivery fee in a different currency from the price', function (): void {
    expect(fn (): AuctionRules => rules([
        'checkoutPrice' => Money::fromMinor(550_000, 'GHS'),
        'deliveryFee' => Money::fromMinor(100, 'USD'),
    ]))->toThrow(InvalidAuctionRules::class);
});

// ------------------------------------------------------------- Cross field

it('rejects a closing window longer than the auction itself', function (): void {
    expect(fn (): AuctionRules => rules([
        'baseDurationSeconds' => 60,
        'closingWindowSeconds' => 120,
    ]))->toThrow(InvalidAuctionRules::class, 'Closing window cannot be longer than the base duration.');
});

it('rejects an extension budget shorter than a single extension', function (): void {
    expect(fn (): AuctionRules => rules([
        'extensionSeconds' => 30,
        'maxExtensions' => 5,
        'maxExtensionTotalSeconds' => 10,
    ]))->toThrow(InvalidAuctionRules::class);
});

it('rejects extensions configured without a closing window to trigger them', function (): void {
    expect(fn (): AuctionRules => rules([
        'closingWindowSeconds' => 0,
        'extensionSeconds' => 10,
        'maxExtensions' => 5,
    ]))->toThrow(InvalidAuctionRules::class);
});

it('accepts extensions being switched off entirely', function (): void {
    $rules = rules([
        'closingWindowSeconds' => 0,
        'extensionSeconds' => 0,
        'maxExtensions' => 0,
        'maxExtensionTotalSeconds' => 0,
    ]);

    expect($rules->extensionsEnabled())->toBeFalse()
        ->and($rules->maximumPossibleDurationSeconds())->toBe(300);
});

// -------------------------------------------------------------- Behaviour

it('reports the longest an auction can possibly run', function (): void {
    // 20 extensions of 10s is 200s, inside the 300s ceiling, so the count wins.
    expect(rules()->maximumPossibleDurationSeconds())->toBe(500);

    // 50 extensions of 10s is 500s, so the 300s absolute ceiling wins instead.
    expect(rules(['maxExtensions' => 50])->maximumPossibleDurationSeconds())->toBe(600);
});

it('treats a zero maximum extension count as extensions disabled', function (): void {
    expect(rules(['maxExtensions' => 0])->extensionsEnabled())->toBeFalse();
});

// --------------------------------------------------------------- Snapshot

it('survives a round trip through its serialized form', function (): void {
    $original = rules([
        'bidCostCredits' => 3,
        'taxBps' => 1_250,
        'deliveryFee' => Money::fromDecimalString('25.50'),
        'forfeitPolicy' => ForfeitPolicy::OfferRunnerUp,
    ]);

    $restored = AuctionRules::fromArray($original->toArray());

    expect($restored->toArray())->toBe($original->toArray())
        ->and($restored->checkoutPrice->minor)->toBe($original->checkoutPrice->minor)
        ->and($restored->deliveryFee->minor)->toBe(2_550)
        ->and($restored->forfeitPolicy)->toBe(ForfeitPolicy::OfferRunnerUp);
});

it('survives a round trip through JSON, which is how it will be stored', function (): void {
    $original = rules(['bidCostCredits' => 2, 'taxBps' => 500]);

    $json = json_encode($original);
    expect($json)->toBeString();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $json, true);

    expect(AuctionRules::fromArray($decoded)->toArray())->toBe($original->toArray());
});

it('refuses a snapshot written by an incompatible version', function (): void {
    $data = rules()->toArray();
    $data['snapshot_version'] = 999;

    expect(fn (): AuctionRules => AuctionRules::fromArray($data))
        ->toThrow(InvalidAuctionRules::class);
});

it('cannot be mutated after construction', function (): void {
    $rules = rules();

    expect(fn () => $rules->bidCostCredits = 99)->toThrow(Error::class);
});

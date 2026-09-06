<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\ForfeitAuction;
use App\Domain\Realtime\AuctionStatePayload;
use App\Enums\AuctionStatus;
use App\Events\Broadcast\AuctionStateBroadcast;
use App\Listeners\AuctionBroadcastSubscriber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
 * What goes on the public auction channel.
 *
 * A broadcast payload is public the moment it is sent: anyone can open a
 * console and subscribe to `auction.{id}`. So these tests are mostly about
 * absence -- what cannot be on the wire -- and about the two properties that
 * let a browser trust what it receives: credits are a count, and sequence
 * orders everything.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();
});

/**
 * The payloads broadcast while running the given work.
 *
 * @return list<array{name: string, payload: array<string, mixed>}>
 */
function broadcastsDuring(Closure $work): array
{
    $captured = [];

    Event::listen(AuctionStateBroadcast::class, function (AuctionStateBroadcast $event) use (&$captured): void {
        $captured[] = [
            'name' => $event->broadcastAs(),
            'payload' => $event->broadcastWith(),
            'channel' => $event->broadcastOn()->name,
        ];
    });

    $work();

    return $captured;
}

// ------------------------------------------------------------- The four events

it('broadcasts an accepted bid on the public auction channel', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    $sent = broadcastsDuring(fn () => placeBid($auction->fresh(), $bidder, 150));

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['name'])->toBe(AuctionBroadcastSubscriber::BID_ACCEPTED)
        ->and($sent[0]['channel'])->toBe('auction.'.$auction->id)
        ->and($sent[0]['payload']['highest_bid_credits'])->toBe(150)
        ->and($sent[0]['payload']['bid_count'])->toBe(1)
        ->and($sent[0]['payload']['sequence'])->toBe(1);
});

it('broadcasts a closure', function (): void {
    $auction = liveAuction();
    placeBid($auction->fresh(), bidder(1_000), 200);

    $sent = broadcastsDuring(fn () => app(CloseAuction::class)->handle($auction->fresh(), force: true));

    $names = array_column($sent, 'name');

    expect($names)->toContain(AuctionBroadcastSubscriber::AUCTION_CLOSED);
});

it('broadcasts a Buy Now sale without naming the buyer', function (): void {
    $product = stockedProduct();
    $auction = liveAuction(product: $product);
    placeBid($auction->fresh(), bidder(1_000), 100);

    $buyer = bidder(1_000);
    $buyer->forceFill(['name' => 'Kofi Mensah'])->saveQuietly();

    $order = buyNowCheckout($buyer, $product->fresh(), $auction->fresh());

    $sent = broadcastsDuring(fn () => payOrder($order));

    $sale = collect($sent)->firstWhere('name', AuctionBroadcastSubscriber::AUCTION_SOLD);

    expect($sale)->not->toBeNull();

    // Participants are told the product was bought, never by whom. Asserted on
    // the payload's shape rather than by searching its text for the buyer's id:
    // a small integer id collides with any digit in a timestamp or a bid count,
    // which would make the test pass or fail for reasons unrelated to privacy.
    // If no field can carry an identity, none does.
    expect(array_keys($sale['payload']))
        ->toEqualCanonicalizing(AuctionStatePayload::allowedKeys())
        ->and(json_encode($sale['payload']))->not->toContain('Kofi Mensah');
});

it('broadcasts a forfeiture', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 100);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    $sent = broadcastsDuring(fn () => app(ForfeitAuction::class)->handle($auction->fresh()));

    expect(array_column($sent, 'name'))->toContain(AuctionBroadcastSubscriber::AUCTION_FORFEITED);
});

// ------------------------------------------------------------------- Privacy

it('carries only the seven whitelisted fields', function (): void {
    $auction = liveAuction();

    $sent = broadcastsDuring(fn () => placeBid($auction->fresh(), bidder(1_000), 150));

    expect(array_keys($sent[0]['payload']))
        ->toEqualCanonicalizing(AuctionStatePayload::allowedKeys());
});

it('names no bidder and leaks no contact detail', function (): void {
    $auction = liveAuction();

    $bidder = bidder(1_000);
    $bidder->forceFill([
        'name' => 'Ama Boateng',
        'email' => 'ama@example.test',
        'phone' => '+233245556666',
    ])->saveQuietly();

    $sent = broadcastsDuring(fn () => placeBid($auction->fresh(), $bidder, 150));

    $encoded = json_encode($sent[0]['payload']);

    foreach (['Ama Boateng', 'ama@example.test', '+233245556666'] as $private) {
        expect($encoded)->not->toContain($private);
    }
});

it('carries no wallet, credit-ledger, order or payment identifier', function (): void {
    $product = stockedProduct();
    $auction = liveAuction(product: $product);
    $bidder = bidder(1_000);

    $sent = broadcastsDuring(fn () => placeBid($auction->fresh(), $bidder, 150));

    $payload = $sent[0]['payload'];

    foreach ([
        'user_id', 'bidder', 'name', 'email', 'phone', 'wallet', 'balance',
        'credit_lot', 'credit_transaction_id', 'order_number', 'order_id',
        'payment', 'reference', 'settlement_amount_minor', 'buy_now',
        'address', 'delivery', 'refund', 'referral', 'winner_user_id',
    ] as $forbidden) {
        expect(array_key_exists($forbidden, $payload))
            ->toBeFalse("payload must not carry {$forbidden}");
    }
});

it('never expresses the highest bid as money', function (): void {
    $auction = liveAuction();

    $sent = broadcastsDuring(fn () => placeBid($auction->fresh(), bidder(1_000), 150));

    $credits = $sent[0]['payload']['highest_bid_credits'];

    // A count, as an integer. Not a formatted string, not a Money object, and
    // never a cedis figure: 150 credits is not GH₵150.
    expect($credits)->toBeInt()->toBe(150);

    $encoded = json_encode($sent[0]['payload']);

    foreach (['GH₵', 'GHS', 'minor', '₵'] as $money) {
        expect($encoded)->not->toContain($money);
    }
});

it('uses the customer-facing status wording, not engine vocabulary', function (): void {
    $auction = liveAuction();

    $sent = broadcastsDuring(fn () => placeBid($auction->fresh(), bidder(1_000), 100));

    expect($sent[0]['payload']['status'])->toBe(AuctionStatus::Live->customerLabel())
        ->and($sent[0]['payload']['status'])->not->toBe(AuctionStatus::Live->value);
});

it('sends ends_at as a UTC ISO-8601 timestamp', function (): void {
    $auction = liveAuction();

    $sent = broadcastsDuring(fn () => placeBid($auction->fresh(), bidder(1_000), 100));

    $endsAt = $sent[0]['payload']['ends_at'];

    expect($endsAt)->toBeString()
        ->and(Carbon::parse($endsAt)->utc()->toIso8601String())->toBe($endsAt);
});

// ------------------------------------------------------------------ Sequence

it('carries the per-auction bid sequence so a client can order deliveries', function (): void {
    $auction = liveAuction();

    $sent = broadcastsDuring(function () use ($auction): void {
        placeBid($auction->fresh(), bidder(1_000), 20);
        placeBid($auction->fresh(), bidder(1_000), 50);
        placeBid($auction->fresh(), bidder(1_000), 100);
    });

    expect(array_column(array_column($sent, 'payload'), 'sequence'))->toBe([1, 2, 3]);
});

it('reports the seconds a bid added to the clock', function (): void {
    $auction = liveAuction();

    $sent = broadcastsDuring(fn () => placeBid($auction->fresh(), bidder(1_000), 100));

    // Extensions are off by default in the seeded ruleset, so this is zero --
    // the field is present either way so the client never has to guess.
    expect($sent[0]['payload'])->toHaveKey('extended_by_seconds')
        ->and($sent[0]['payload']['extended_by_seconds'])->toBeInt();
});

// ------------------------------------------ The payload is built, not serialized

it('builds the payload explicitly rather than serializing a model', function (): void {
    $auction = liveAuction();
    placeBid($auction->fresh(), bidder(1_000), 175);

    $payload = AuctionStatePayload::for($auction->fresh(), sequence: 1)->toArray();

    expect($payload)->toBe([
        'auction_id' => $auction->id,
        'status' => AuctionStatus::Live->customerLabel(),
        'highest_bid_credits' => 175,
        'bid_count' => 1,
        'ends_at' => $auction->fresh()->ends_at->toIso8601String(),
        'sequence' => 1,
        'extended_by_seconds' => 0,
    ]);
});

it('reports no bids as absent rather than as zero credits', function (): void {
    $auction = liveAuction();

    $payload = AuctionStatePayload::for($auction->fresh())->toArray();

    // No bids at all is a different state from a bid of nothing.
    expect($payload['highest_bid_credits'])->toBeNull()
        ->and($payload['bid_count'])->toBe(0);
});

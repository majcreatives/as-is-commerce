<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\ForfeitAuction;
use App\Enums\AuctionStatus;
use App\Enums\OrderStatus;
use App\Livewire\Auctions\AuctionRoom;
use App\Models\Bid;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;

/*
 * A broadcast outage is not a commerce outage.
 *
 * This is the requirement the whole transport design answers to. Bids,
 * closures, Buy Now, forfeiture and settlement are decided in MySQL under row
 * locks and committed before anything is broadcast, so a transport that is
 * down, slow, misconfigured or throwing must be invisible to commerce.
 *
 * Every test here runs against a broadcaster that throws on every call --
 * which is what an unreachable Reverb, a refused connection or a bad key
 * actually looks like from inside the application.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    // A transport that fails at every opportunity.
    Broadcast::extend('exploding', fn (): Broadcaster => new class implements Broadcaster
    {
        public function auth($request): mixed
        {
            throw new RuntimeException('reverb is unreachable');
        }

        public function validAuthenticationResponse($request, $result): mixed
        {
            throw new RuntimeException('reverb is unreachable');
        }

        public function broadcast(array $channels, $event, array $payload = []): void
        {
            throw new RuntimeException('reverb is unreachable');
        }
    });

    config(['broadcasting.default' => 'exploding']);
});

// ----------------------------------------------------------------- Bidding

it('accepts a bid when broadcasting throws', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    $bid = placeBid($auction->fresh(), $bidder, 150);

    // The bid exists, the credits are gone, the projection moved. The
    // transport failing changed none of it.
    expect($bid->amount_credits)->toBe(150)
        ->and($bid->credit_transaction_id)->not->toBeNull()
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(850)
        ->and($auction->fresh()->highest_bid_credits)->toBe(150)
        ->and($auction->fresh()->bid_count)->toBe(1);
});

it('does not roll back a committed bid because delivery failed', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    placeBid($auction->fresh(), $bidder, 100);
    placeBid($auction->fresh(), $bidder, 200);

    // Two committed bids, two committed consumptions, whatever the wire did.
    expect(Bid::where('auction_id', $auction->id)->count())->toBe(2)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(700);
});

it('lets a bidder bid through the auction room when broadcasting throws', function (): void {
    $auction = cumulativeAuction(minimum: 150, increment: 10);
    $bidder = bidder(1_000);

    // Through the interface, so a transport exception would surface as a 500
    // for a bidder whose credits had already been consumed.
    Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->call('review', 150)
        ->call('bid')
        ->assertHasNoErrors()
        ->assertOk();

    expect($auction->fresh()->highest_bid_credits)->toBe(150);
});

// ----------------------------------------------------------------- Closing

it('closes an auction and picks the right winner when broadcasting throws', function (): void {
    $auction = liveAuction();
    $low = bidder(1_000);
    $high = bidder(1_000);

    placeBid($auction->fresh(), $low, 100);
    placeBid($auction->fresh(), $high, 250);

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->winner_user_id)->toBe($high->id)
        // The settlement checkout opened in the same transaction.
        ->and($closed->settlementOrder()->count())->toBe(1);
});

it('runs the whole clock sweep when broadcasting throws', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 300);

    $this->travel(10)->minutes();

    $this->artisan('auctions:tick')->assertSuccessful();

    expect($auction->fresh()->winner_user_id)->toBe($winner->id);
});

// ----------------------------------------------------------------- Buy Now

it('completes a Buy Now and ends the auction when broadcasting throws', function (): void {
    $product = stockedProduct();
    $auction = liveAuction(product: $product);
    $bidder = bidder(1_000);

    placeBid($auction->fresh(), $bidder, 100);

    $buyer = bidder(1_000);
    $order = buyNowCheckout($buyer, $product->fresh(), $auction->fresh());

    payOrder($order);

    $ended = $auction->fresh();

    // The first successful acquisition wins, the standing highest bidder does
    // not, and their credits stay consumed.
    expect($ended->buy_now_user_id)->toBe($buyer->id)
        ->and($ended->winner_user_id)->toBeNull()
        // Paid, not Processing: an order moves to Processing only when staff
        // start preparing the delivery, which is Stage 11's rule and is
        // unchanged here.
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(900)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

// -------------------------------------------------------------- Forfeiture

it('forfeits when broadcasting throws', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 200);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);
    app(ForfeitAuction::class)->handle($auction->fresh());

    expect($auction->fresh()->status)->toBe(AuctionStatus::Forfeited)
        // Credits stay consumed through forfeiture, as through every outcome.
        ->and(creditWalletFor($winner)->fresh()->balance)->toBe(800);
});

// -------------------------------------------------------------- Settlement

it('settles a won auction when broadcasting throws', function (): void {
    $product = stockedProduct();
    $auction = liveAuction(product: $product);
    $winner = bidder(1_000);

    placeBid($auction->fresh(), $winner, 250);

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);
    $order = $closed->settlementOrder()->first();

    payOrder($order);

    expect($auction->fresh()->status)->toBe(AuctionStatus::Settled)
        // The settlement is its own GHS figure, never a conversion of credits.
        ->and($order->fresh()->total_minor)->toBe($closed->settlement_amount_minor)
        ->and(creditWalletFor($winner)->fresh()->balance)->toBe(750)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

// --------------------------------------------------- The page still works

it('renders the auction room when broadcasting throws', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);
    placeBid($auction->fresh(), $bidder, 175);

    Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertOk()
        // The authoritative figure, rendered by the server as it always was.
        ->assertSee('175')
        // And still polling, which is the only mechanism production relies on.
        ->assertSee('wire:poll', escape: false);
});

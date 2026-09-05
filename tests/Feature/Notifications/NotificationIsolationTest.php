<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Enums\AuctionStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Models\Bid;
use App\Models\InventoryTransaction;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The guarantee the whole notification layer rests on.
 *
 * Notifications are informational. Not one of them is allowed to fail a bid, a
 * credit consumption, an auction closure, a payment verification, an order or
 * an inventory sale. These tests break the notification layer as thoroughly as
 * it can be broken, and then check that the platform carried on regardless.
 *
 * A dispatcher that throws on everything stands in for every real failure
 * mode at once: an unreachable mail server, a full disk, a schema that has
 * drifted, a bug in a message template.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);
});

/**
 * Replace the dispatcher with one that fails at everything.
 */
function breakNotifications(): void
{
    app()->bind(NotificationDispatcher::class, fn (): NotificationDispatcher => new class extends NotificationDispatcher
    {
        public function send(
            User $recipient,
            NotificationType $type,
            string $title,
            string $message,
            ?string $eventKey = null,
            ?string $actionUrl = null,
            ?string $actionLabel = null,
            array $context = [],
        ): ?Notification {
            throw new RuntimeException('The notification layer is broken.');
        }
    });
}

it('still places a bid and consumes its credits', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    breakNotifications();

    $bid = placeBid($auction, $bidder, 150);

    expect($bid->amount_credits)->toBe(150)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(850)
        ->and(Bid::count())->toBe(1)
        // And nothing was written, which is the honest outcome.
        ->and(Notification::count())->toBe(0);
});

it('still closes an auction and names its winner', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    $loser = bidder(500);

    placeBid($auction, $loser, 50);
    placeBid($auction, $winner, 200);

    breakNotifications();

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->winner_user_id)->toBe($winner->id)
        // The settlement checkout still exists: closing is not conditional on
        // anybody being told about it.
        ->and($closed->settlementOrder)->not->toBeNull();
});

it('still verifies a payment and sells the unit', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());

    breakNotifications();

    payOrder($order->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

it('still terminates an auction on a Buy Now', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    placeBid($auction, bidder(1_000), 400);

    breakNotifications();

    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));

    expect($auction->fresh()->status)->toBe(AuctionStatus::Settled)
        ->and($auction->fresh()->buy_now_user_id)->not->toBeNull()
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

it('still settles an auction winner', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $order = app(CloseAuction::class)->handle($auction, force: true)->settlementOrder;

    breakNotifications();

    payOrder($order->fresh());

    expect($auction->fresh()->status)->toBe(AuctionStatus::Settled)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

it('still records a blocked fulfilment', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $slow = buyNowCheckout(bidder(), $product->fresh(), $auction->fresh());
    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));

    breakNotifications();

    payOrder($slow->fresh());

    expect($slow->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($slow->fresh()->isFulfilmentBlocked())->toBeTrue();
});

it('still runs the whole operational clock', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    breakNotifications();

    $this->travel(10)->minutes();
    $this->artisan('auctions:tick')->assertSuccessful();

    expect($auction->fresh()->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($auction->fresh()->winner_user_id)->toBe($winner->id);
});

/*
 * The other half of the guarantee: a notification must never be written for
 * something that did not happen. A failed bid rolls back, and nothing is
 * dispatched, because the dispatch happens after the commit rather than
 * inside it.
 */
it('writes no notification for a transaction that rolled back', function (): void {
    $auction = liveAuction();
    $bidder = bidder(50);

    expect(fn (): Bid => placeBid($auction, $bidder, 500))
        ->toThrow(BidRejected::class);

    expect(Notification::count())->toBe(0)
        ->and(Bid::count())->toBe(0)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(50);
});

/*
 * Notifications are downstream of everything and upstream of nothing. Deleting
 * every one of them leaves the ledgers, the auctions, the orders and the
 * inventory exactly as they were.
 */
it('leaves the platform intact when every notification is deleted', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);

    placeBid($auction, $winner, 200);
    $order = app(CloseAuction::class)->handle($auction, force: true)->settlementOrder;
    payOrder($order->fresh());

    expect(Notification::count())->toBeGreaterThan(0);

    DB::table('notifications')->delete();

    expect($auction->fresh()->status)->toBe(AuctionStatus::Settled)
        ->and($auction->fresh()->winner_user_id)->toBe($winner->id)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(creditWalletFor($winner)->fresh()->balance)->toBe(300)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

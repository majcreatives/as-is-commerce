<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\ForfeitAuction;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\InventoryTransactionType;
use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Mail\PlatformNotificationMail;
use App\Models\AuctionRuleset;
use App\Models\Bid;
use App\Models\InventoryTransaction;
use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/*
 * What the platform tells people, and -- far more importantly -- what it
 * cannot break by telling them.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->close = app(CloseAuction::class);
    $this->orders = app(OrderLifecycle::class);
});

/**
 * Every notification this user has, of a given type.
 *
 * @return Collection<int, Notification>
 */
function notificationsFor(User $user, ?NotificationType $type = null)
{
    return Notification::query()
        ->where('notifiable_id', $user->id)
        ->when($type !== null, fn ($q) => $q->where('event_type', $type->value))
        ->get();
}

// ------------------------------------------------------------- Bidding

it('confirms an accepted bid to the bidder', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Nokia Handset']);
    $auction = liveAuction(product: $product);
    $bidder = bidder(1_000);

    placeBid($auction, $bidder, 150);

    $notification = notificationsFor($bidder, NotificationType::BidPlaced)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->message)->toContain('150 Credits')
        ->and($notification->message)->toContain('Nokia Handset')
        // Credits are a count. They never appear as money.
        ->and($notification->message)->not->toContain('GH₵150')
        // And the message says plainly that they are gone.
        ->and($notification->message)->toContain('not refunded');
});

it('tells the displaced bidder they were outbid', function (): void {
    $auction = liveAuction();
    $first = bidder(1_000);
    $second = bidder(1_000);

    placeBid($auction, $first, 100);
    placeBid($auction, $second, 300);

    $outbid = notificationsFor($first, NotificationType::Outbid)->first();

    expect($outbid)->not->toBeNull()
        ->and($outbid->message)->toContain('300 Credits');
});

/*
 * A bidder never learns who beat them. Only the amount to beat is disclosed.
 */
it('never names the other bidder in an outbid message', function (): void {
    $auction = liveAuction();
    $first = bidder(1_000);
    $second = bidder(1_000);
    $second->update(['name' => 'Kwame Mensah']);

    placeBid($auction, $first, 100);
    placeBid($auction, $second, 300);

    $outbid = notificationsFor($first, NotificationType::Outbid)->first();

    expect($outbid->message)->not->toContain('Kwame')
        ->and($outbid->message)->not->toContain($second->phone)
        ->and(json_encode($outbid->data))->not->toContain('Kwame');
});

it('does not tell a bidder they outbid themselves', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->create(['allow_bid_increase' => true]);

    $auction = liveAuction(ruleset: $ruleset);
    $bidder = bidder(1_000);

    placeBid($auction, $bidder, 100);
    placeBid($auction, $bidder, 300);

    expect(notificationsFor($bidder, NotificationType::Outbid))->toHaveCount(0);
});

it('writes nothing when a bid is refused', function (): void {
    $auction = liveAuction(
        ruleset: AuctionRuleset::factory()->active()->withoutThrottle()
            ->withBidRules(minimum: 100)->create()
    );
    $bidder = bidder(1_000);

    expect(fn (): Bid => placeBid($auction, $bidder, 20))
        ->toThrow(BidRejected::class);

    expect(Notification::count())->toBe(0);
});

// -------------------------------------------------------- How it ended

it('tells the winner what they won and what they owe, in different units', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);

    placeBid($auction, $winner, 180);
    $this->close->handle($auction, force: true);

    $won = notificationsFor($winner, NotificationType::AuctionWon)->first();

    expect($won)->not->toBeNull()
        // The bid, as Credits.
        ->and($won->message)->toContain('180 Credits')
        ->and($won->message)->toContain('already consumed')
        // The settlement, as money, and named as its own thing.
        ->and($won->message)->toContain('Auction Settlement Amount')
        ->and($won->message)->toContain('GH₵100.00')
        // Never the suggestion that one became the other.
        ->and($won->message)->not->toContain('converted')
        ->and($won->message)->not->toContain('GH₵180');
});

it('tells losing bidders plainly, and promises them nothing', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    $loser = bidder(500);

    placeBid($auction, $loser, 80);
    placeBid($auction, $winner, 200);
    $this->close->handle($auction, force: true);

    $lost = notificationsFor($loser, NotificationType::AuctionLost)->first();

    expect($lost)->not->toBeNull()
        ->and($lost->message)->toContain('did not win')
        ->and($lost->message)->toContain('200 Credits')
        ->and($lost->message)->toContain('remain consumed')
        ->and($lost->message)->toContain('not refunded')
        // No refund is offered, promised, or hinted at.
        ->and($lost->message)->not->toContain('refund will')
        ->and($lost->message)->not->toContain('returned to');
});

it('does not tell the winner they lost', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 200);
    $this->close->handle($auction, force: true);

    expect(notificationsFor($winner, NotificationType::AuctionLost))->toHaveCount(0)
        ->and(notificationsFor($winner, NotificationType::AuctionWon))->toHaveCount(1);
});

it('tells bidders a product was sold outright without naming the buyer', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $bidder = bidder(1_000);
    $buyer = bidder();
    $buyer->update(['name' => 'Ama Boateng']);

    placeBid($auction, $bidder, 400);
    payOrder(buyNowCheckout($buyer, $product->fresh(), $auction->fresh()));

    $told = notificationsFor($bidder, NotificationType::AuctionSoldViaBuyNow)->first();

    expect($told)->not->toBeNull()
        ->and($told->message)->toContain('Sold via Buy Now')
        ->and($told->message)->toContain('remain consumed')
        ->and($told->message)->not->toContain('Ama');
});

it('tells the winner when their settlement deadline lapses', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);
    $this->close->handle($auction, force: true);

    $this->travel(3)->hours();
    app(ForfeitAuction::class)->handle($auction->fresh());

    $forfeited = notificationsFor($winner, NotificationType::SettlementForfeited)->first();

    expect($forfeited)->not->toBeNull()
        ->and($forfeited->message)->toContain('remain consumed')
        ->and($forfeited->message)->not->toContain('refund');
});

// ---------------------------------------------------------- Settlement

it('tells the winner their settlement checkout is ready', function (): void {
    $auction = liveAuction(settlementMinor: 10_000);
    $winner = bidder(500);
    placeBid($auction, $winner, 180);

    $this->close->handle($auction, force: true);

    $ready = notificationsFor($winner, NotificationType::SettlementCreated)->first();

    expect($ready)->not->toBeNull()
        ->and($ready->message)->toContain('GH₵100.00')
        ->and($ready->message)->toContain('already consumed')
        ->and($ready->action_url)->toContain('/checkout/');
});

// ------------------------------------------------------ Orders and money

it('confirms a payment only once the server has verified it', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product->fresh());

    // Opening a payment is not a payment.
    initializePayment($order);
    expect(notificationsFor($buyer, NotificationType::OrderPaymentSuccess))->toHaveCount(0);

    payOrder($order->fresh());

    expect(notificationsFor($buyer, NotificationType::OrderPaymentSuccess))->toHaveCount(1);
});

it('tells a customer their paid order could not be completed', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $slow = bidder();

    $slowOrder = buyNowCheckout($slow, $product->fresh(), $auction->fresh());
    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));
    payOrder($slowOrder);

    $blocked = notificationsFor($slow, NotificationType::OrderFulfilmentBlocked)->first();

    expect($blocked)->not->toBeNull()
        ->and($blocked->message)->toContain('received your payment')
        ->and($blocked->message)->toContain('support team')
        // No refund is promised, because none exists.
        ->and($blocked->message)->not->toContain('refund')
        ->and($blocked->message)->not->toContain('returned');
});

it('tells a customer when a late payment lands on a closed checkout', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product->fresh());
    $payment = initializePayment($order);

    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());

    payOrder($order->fresh(), $payment->fresh());

    expect(notificationsFor($buyer, NotificationType::OrderFulfilmentBlocked))->toHaveCount(1)
        // Not resurrected, and not announced as paid.
        ->and(notificationsFor($buyer, NotificationType::OrderPaymentSuccess))->toHaveCount(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PaymentExpired);
});

it('tells a customer when an order is fulfilled', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product->fresh());
    payOrder($order);

    $this->orders->advance($order->fresh(), OrderStatus::Fulfilled, userWithRole('admin'));

    expect(notificationsFor($buyer, NotificationType::OrderFulfilled))->toHaveCount(1);
});

it('says nothing about an order merely being processed', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product->fresh());
    payOrder($order);

    $before = notificationsFor($buyer)->count();
    $this->orders->advance($order->fresh(), OrderStatus::Processing, userWithRole('admin'));

    // Internal progress is not news.
    expect(notificationsFor($buyer)->count())->toBe($before);
});

// -------------------------------------------------------- Idempotency

it('produces one notification however many times a webhook is delivered', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product->fresh());
    $payment = initializePayment($order);

    foreach (range(1, 5) as $ignored) {
        payOrder($order->fresh(), $payment->fresh());
    }

    expect(notificationsFor($buyer, NotificationType::OrderPaymentSuccess))->toHaveCount(1)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

it('produces one bid confirmation however many times the request is retried', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    foreach (range(1, 4) as $ignored) {
        placeBid($auction->fresh(), $bidder, 150, 'retried-bid');
    }

    expect(notificationsFor($bidder, NotificationType::BidPlaced))->toHaveCount(1)
        ->and(Bid::count())->toBe(1);
});

it('produces one closing announcement however many sweeps run', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    $loser = bidder(500);

    placeBid($auction, $loser, 50);
    placeBid($auction, $winner, 200);

    foreach (range(1, 4) as $ignored) {
        $this->close->handle($auction->fresh(), force: true);
    }

    expect(notificationsFor($winner, NotificationType::AuctionWon))->toHaveCount(1)
        ->and(notificationsFor($loser, NotificationType::AuctionLost))->toHaveCount(1)
        ->and(notificationsFor($winner, NotificationType::SettlementCreated))->toHaveCount(1);
});

it('deduplicates on the event rather than on the wording', function (): void {
    $user = bidder();
    $dispatcher = app(NotificationDispatcher::class);

    $first = $dispatcher->send(
        recipient: $user,
        type: NotificationType::OrderPaymentSuccess,
        title: 'Payment received',
        message: 'One wording.',
        eventKey: 'the-same-event',
    );

    // Different words entirely, same event. Still one notification.
    $second = $dispatcher->send(
        recipient: $user,
        type: NotificationType::OrderPaymentSuccess,
        title: 'Completely different title',
        message: 'Completely different message.',
        eventKey: 'the-same-event',
    );

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(notificationsFor($user))->toHaveCount(1)
        ->and(notificationsFor($user)->first()->message)->toBe('One wording.');
});

// ------------------------------------------------- Preferences

it('respects a switched-off optional category', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    $bidder->notification_preferences = $bidder->notificationPreferences()
        ->with(NotificationCategory::Bidding, inApp: false, email: false)
        ->toArray();
    $bidder->save();

    placeBid($auction, $bidder->fresh(), 150);

    expect(notificationsFor($bidder, NotificationType::BidPlaced))->toHaveCount(0);
});

/*
 * The rule that matters most in the preference system: a customer cannot
 * switch off being told that the platform has their money.
 */
it('sends transactional notifications whatever the preferences say', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();

    // Everything the customer is allowed to switch off, switched off.
    $preferences = $buyer->notificationPreferences();

    foreach (NotificationCategory::optionalCases() as $category) {
        $preferences = $preferences->with($category, inApp: false, email: false);
    }

    $buyer->notification_preferences = $preferences->toArray();
    $buyer->save();

    payOrder(buyNowCheckout($buyer->fresh(), $product->fresh()));

    expect(notificationsFor($buyer, NotificationType::OrderPaymentSuccess))->toHaveCount(1);
});

it('treats an account that never set preferences as wanting everything', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    expect($bidder->notification_preferences)->toBeNull();

    placeBid($auction, $bidder, 150);

    expect(notificationsFor($bidder, NotificationType::BidPlaced))->toHaveCount(1);
});

// ------------------------------------------------------------ Email

it('emails a customer about money, and does not email about a bid', function (): void {
    Mail::fake();

    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();
    $buyer->update(['email' => 'buyer@example.test']);

    $auction = liveAuction();
    placeBid($auction, $buyer->fresh(), 100);

    // A bid is not worth an email; one per bid on a busy auction is how an
    // address gets marked as spam.
    Mail::assertNothingSent();

    payOrder(buyNowCheckout($buyer->fresh(), $product->fresh()));

    Mail::assertSent(PlatformNotificationMail::class);
});

it('sends no email to an account that has none', function (): void {
    Mail::fake();

    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    // The factory gives an account an email; phone is the primary identity on
    // this platform and an account with no email is entirely ordinary.
    $buyer = bidder();
    $buyer->email = null;
    $buyer->save();

    expect($buyer->fresh()->email)->toBeNull();

    payOrder(buyNowCheckout($buyer->fresh(), $product->fresh()));

    Mail::assertNothingSent();

    // The in-app notification still reached them, which is the one that counts.
    expect(notificationsFor($buyer, NotificationType::OrderPaymentSuccess))->toHaveCount(1);
});

it('records a failed email rather than raising it', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();
    $buyer->update(['email' => 'buyer@example.test']);

    // A mail transport that refuses everything.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP is unreachable'));

    payOrder(buyNowCheckout($buyer->fresh(), $product->fresh()));

    $notification = notificationsFor($buyer, NotificationType::OrderPaymentSuccess)->first();

    expect($notification)->not->toBeNull()
        ->and($notification->mailFailed())->toBeTrue()
        ->and($notification->mail_failure_reason)->toContain('SMTP is unreachable')
        // And the business event completed regardless.
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

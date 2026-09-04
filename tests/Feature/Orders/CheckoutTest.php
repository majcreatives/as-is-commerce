<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\AuctionRuleset;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\QueryException;

/*
 * Opening a checkout: what it freezes, what it holds, and what it does not do.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->orders = app(OrderLifecycle::class);
    $this->close = app(CloseAuction::class);
});

// ------------------------------------------------------------- Buy Now

it('prices a plain catalog purchase at the product price', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);

    expect($order->source)->toBe(OrderSource::BuyNow)
        ->and($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->subtotal_minor)->toBe(550_000)
        ->and($order->discount_minor)->toBe(0)
        ->and($order->total_minor)->toBe(550_000);
});

/*
 * The worked example from the brief, as a real order.
 */
it('takes one cedi off per consumed bid credit', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);

    placeBid($auction, $buyer, 150);

    $order = buyNowCheckout($buyer, $product, $auction);

    expect($order->subtotal_minor)->toBe(550_000)
        ->and($order->discount_credits)->toBe(150)
        ->and($order->discount_minor)->toBe(15_000)
        ->and($order->total_minor)->toBe(535_000)
        ->and($order->total()->toDecimalString())->toBe('5350.00');
});

it('offers no discount for credits spent on another auction', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $elsewhere = liveAuction();
    $buyer = bidder(1_000);

    placeBid($elsewhere, $buyer, 400);

    $order = buyNowCheckout($buyer, $product, $auction);

    expect($order->discount_credits)->toBe(0)
        ->and($order->total_minor)->toBe(550_000);
});

it('offers no discount for unused credits in the wallet', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(5_000);

    placeBid($auction, $buyer, 10);

    $order = buyNowCheckout($buyer, $product, $auction);

    // 4,990 credits sitting unused buy nothing. Ten were consumed.
    expect(creditWalletFor($buyer)->fresh()->balance)->toBe(4_990)
        ->and($order->discount_credits)->toBe(10)
        ->and($order->discount_minor)->toBe(1_000);
});

it('does not deduct any credits at checkout', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);

    placeBid($auction, $buyer, 150);
    $before = creditWalletFor($buyer)->fresh()->balance;

    buyNowCheckout($buyer, $product, $auction);

    // The credits were consumed when the bid was placed. Checkout charges
    // cedis and touches no wallet.
    expect(creditWalletFor($buyer)->fresh()->balance)->toBe($before)->toBe(850);
});

/*
 * The rule that stops an abandoned checkout killing a live auction.
 */
it('does not end the auction when a checkout is opened', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    buyNowCheckout(bidder(), $product, $auction);

    $auction->refresh();

    expect($auction->status->acceptsBids())->toBeTrue()
        ->and($auction->buy_now_user_id)->toBeNull()
        ->and($auction->closure_reason)->toBeNull();
});

it('lets bidding continue while a checkout is open', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    buyNowCheckout(bidder(), $product, $auction);

    expect(placeBid($auction->fresh(), bidder(), 300)->amount_credits)->toBe(300);
});

// ------------------------------------------------------------ Reservations

it('holds a unit for a plain catalog checkout', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);

    expect($order->holds_reservation)->toBeTrue()
        ->and($product->fresh()->stock_reserved)->toBe(1)
        ->and($product->fresh()->availableStock())->toBe(0);
});

/*
 * The auction is already holding the unit that would be bought. Reserving
 * again would take two units off the shelf for one sale.
 */
it('holds nothing extra for an auction-linked checkout', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $order = buyNowCheckout(bidder(), $product, $auction);

    expect($order->holds_reservation)->toBeFalse()
        ->and($product->fresh()->stock_reserved)->toBe(1);
});

it('refuses a second catalog checkout when the last unit is held', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    buyNowCheckout(bidder(), $product);

    // Refused before any stock movement is attempted: holding the last unit
    // takes the product out of stock, and the catalog's own purchasable rule
    // catches it with a message a customer can read.
    expect(fn (): Order => buyNowCheckout(bidder(), $product->fresh()))
        ->toThrow(InvalidCheckout::class, 'not available to buy');

    expect($product->fresh()->stock_reserved)->toBe(1);
});

it('refuses a second open checkout from the same customer', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 5);

    $buyer = bidder();
    buyNowCheckout($buyer, $product);

    expect(fn (): Order => buyNowCheckout($buyer, $product->fresh()))
        ->toThrow(InvalidCheckout::class, 'already have an open checkout');
});

it('gives the unit back when a checkout is cancelled', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);
    $this->orders->cancel($order, 'Changed my mind.');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->holds_reservation)->toBeFalse()
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('gives the unit back when a checkout expires', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);

    expect($product->fresh()->availableStock())->toBe(0);

    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('releases a held unit only once', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 2);

    $order = buyNowCheckout(bidder(), $product);

    $this->orders->releaseReservation($order, null, 'first');
    $this->orders->releaseReservation($order->fresh(), null, 'second');

    // Two calls, one release: available stock is 2, not 3.
    expect($product->fresh()->availableStock())->toBe(2)
        ->and($product->fresh()->stock_reserved)->toBe(0);
});

// -------------------------------------------------------- Settlement

it('prices a settlement at the auction settlement amount', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);

    placeBid($auction, $winner, 180);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    expect($order->source)->toBe(OrderSource::AuctionWin)
        ->and($order->subtotal_minor)->toBe(10_000)
        ->and($order->total()->toDecimalString())->toBe('100.00');
});

/*
 * The three numbers, kept apart in one assertion.
 */
it('keeps settlement independent of the Buy Now price and the winning bid', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);

    placeBid($auction, $winner, 180);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    expect($order->total_minor)->toBe(10_000)
        // Not the product's price.
        ->and($order->total_minor)->not->toBe(550_000)
        // Not the bid converted into cedis.
        ->and($order->total_minor)->not->toBe(18_000)
        ->and($order->pricing()->winningBidCredits)->toBe(180);
});

it('gives a winner no credit discount on their settlement', function (): void {
    $auction = liveAuction(settlementMinor: 10_000);
    $winner = bidder(1_000);

    placeBid($auction, $winner, 400);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    // Their 400 credits bought them the win. They do not also reduce what
    // winning costs.
    expect($order->discount_minor)->toBe(0)
        ->and($order->discount_credits)->toBe(0)
        ->and($order->total_minor)->toBe(10_000);
});

it('refuses at the database level to put a discount on a settlement', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    expect(fn () => DB::table('orders')->where('id', $order->id)->update([
        'discount_minor' => 1_000,
        'discount_credits' => 10,
        'total_minor' => $order->total_minor - 1_000,
    ]))->toThrow(QueryException::class);
});

it('charges the winner no credits at settlement checkout', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);

    placeBid($auction, $winner, 250);
    $this->close->handle($auction, force: true);

    $before = creditWalletFor($winner)->fresh()->balance;
    settlementCheckout($auction->fresh(), $winner);

    expect(creditWalletFor($winner)->fresh()->balance)->toBe($before)->toBe(750);
});

it('refuses a settlement checkout from anyone but the winner', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);
    $this->close->handle($auction, force: true);

    expect(fn (): Order => settlementCheckout($auction->fresh(), bidder()))
        ->toThrow(InvalidCheckout::class, 'Only the auction winner');
});

it('refuses a settlement checkout on an auction that has not closed', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    expect(fn (): Order => settlementCheckout($auction->fresh(), $winner))
        ->toThrow(InvalidCheckout::class, 'not awaiting settlement');
});

it('hands back the same checkout when a winner asks twice', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);
    $this->close->handle($auction, force: true);

    $first = settlementCheckout($auction->fresh(), $winner);
    $second = settlementCheckout($auction->fresh(), $winner);

    expect($second->id)->toBe($first->id)
        ->and(Order::count())->toBe(1);
});

it('reserves nothing for a settlement, because the auction already holds it', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    expect($order->holds_reservation)->toBeFalse()
        ->and($auction->product->fresh()->stock_reserved)->toBe(1);
});

it('takes its deadline from the auction frozen rules', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->create(['checkout_deadline_minutes' => 90]);

    $auction = liveAuction(ruleset: $ruleset);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    expect(now()->diffInMinutes($order->payment_due_at))->toEqualWithDelta(90, 1);
});

// ------------------------------------------------------- The frozen record

it('freezes the derivation, not just the total', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(1_000);
    placeBid($auction, $buyer, 150);

    $pricing = buyNowCheckout($buyer, $product, $auction)->pricing();

    expect($pricing->subtotal->minor)->toBe(550_000)
        ->and($pricing->discountCredits)->toBe(150)
        // The rate is frozen too, so the arithmetic can be rechecked years
        // later against neither figure having moved.
        ->and($pricing->discountRateMinorPerCredit)->toBe(100)
        ->and($pricing->total->minor)->toBe(535_000);
});

it('does not follow the product when it is repriced afterwards', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);

    app(ProductService::class)
        ->update($product->fresh(), ['buy_now_price_minor' => 999_900]);

    expect($order->fresh()->total_minor)->toBe(550_000)
        ->and($order->fresh()->item()->unit_price_minor)->toBe(550_000);
});

it('snapshots the product name and sku on the item', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Original Name']);
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);
    $sku = $product->sku;

    app(ProductService::class)
        ->update($product->fresh(), ['name' => 'Renamed Later']);

    $item = $order->fresh()->item();

    expect($item->product_name_snapshot)->toBe('Original Name')
        ->and($item->sku_snapshot)->toBe($sku);
});

it('refuses a total that does not equal its parts', function (): void {
    $order = Order::factory()->create();

    expect(fn () => DB::table('orders')->where('id', $order->id)
        ->update(['total_minor' => 1]))
        ->toThrow(QueryException::class);
});

it('keeps order items append-only', function (): void {
    $order = Order::factory()->create();

    expect(fn () => DB::table('order_items')->where('order_id', $order->id)
        ->update(['unit_price_minor' => 1]))
        ->toThrow(QueryException::class, 'never edited');
});

it('records the opening of a checkout in the order history', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);
    $transition = $order->transitions()->first();

    expect($transition?->from_status)->toBeNull()
        ->and($transition?->to_status)->toBe(OrderStatus::PendingPayment);
});

it('keeps order history append-only', function (): void {
    $order = Order::factory()->create();

    app(OrderLifecycle::class)
        ->cancel($order, 'Testing.');

    $transition = $order->transitions()->first();

    expect(fn () => DB::table('order_transitions')->where('id', $transition->id)
        ->update(['reason' => 'rewritten']))
        ->toThrow(QueryException::class, 'append-only');
});

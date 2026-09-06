<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Actions\InitializeOrderPayment;
use App\Domain\Orders\Exceptions\InvalidOrderTransition;
use App\Domain\Orders\Exceptions\OrderRecordIsFrozen;
use App\Domain\Orders\Exceptions\PaymentNotAcceptable;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Enums\CreditTransactionType;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\CreditTransaction;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

/*
 * Paying for an order: what has to be true before a product changes hands.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    // Set before anything resolves the gateway: it reads the key when it is
    // constructed, so a service resolved first would hold a null secret. Never
    // a real credential -- the HTTP client is faked throughout.
    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->orders = app(OrderLifecycle::class);
    $this->close = app(CloseAuction::class);

    /*
     * Fulfilment is resolved in each test rather than here, on purpose.
     *
     * The gateway is constructed with the HTTP factory injected into it, and
     * `fakeHttp()` swaps the factory the container hands out. A service
     * resolved before the stubs are installed keeps the real factory and tries
     * to reach Paystack -- so it must be resolved after them. Same family of
     * trap as `Http::fake()` appending rather than replacing.
     */
    $this->fulfil = fn (): FulfillOrderPayment => app(FulfillOrderPayment::class);
});

// ------------------------------------------------------- Initialization

it('asks the provider for exactly the frozen total', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct(550_000));

    $payment = initializePayment($order);

    expect($payment->amount_minor)->toBe($order->total_minor)->toBe(550_000)
        ->and($payment->currency)->toBe('GHS')
        ->and($payment->status)->toBe(OrderPaymentStatus::Pending)
        ->and($payment->authorization_url)->toBe('https://checkout.paystack.com/test');
});

it('generates its own reference rather than taking the provider one', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    $payment = initializePayment($order);

    // The provider echoed back a different reference in the stub. Ours is what
    // is recorded, because ours is what identifies the payment to us.
    expect($payment->provider_reference)->toStartWith('AIC-P-')
        ->and($payment->provider_reference)->not->toBe('ignored-the-server-sends-its-own');
});

it('refuses to open a payment on an expired checkout', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    $this->travel(2)->hours();

    expect(fn (): OrderPayment => app(InitializeOrderPayment::class)->handle($order->fresh()))
        ->toThrow(PaymentNotAcceptable::class, 'expired');
});

it('refuses to open a payment on an order that is not awaiting one', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $this->orders->cancel($order, 'Testing.');

    expect(fn (): OrderPayment => app(InitializeOrderPayment::class)->handle($order->fresh()))
        ->toThrow(PaymentNotAcceptable::class, 'not awaiting payment');
});

it('abandons an earlier attempt when a new one is opened', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    $first = initializePayment($order);
    $second = initializePayment($order);

    expect($first->fresh()->status)->toBe(OrderPaymentStatus::Abandoned)
        ->and($second->status)->toBe(OrderPaymentStatus::Pending)
        // Never two open attempts competing to be verified.
        ->and(OrderPayment::where('order_id', $order->id)->open()->count())->toBe(1);
});

it('refuses to rewrite what the provider was asked for', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    expect(fn () => DB::table('order_payments')->where('id', $payment->id)
        ->update(['amount_minor' => 1]))
        ->toThrow(QueryException::class, 'cannot be changed');
});

// ------------------------------------------------------------ Verification

it('completes an order on a verified payment', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct(550_000));

    $result = payOrder($order);

    $order->refresh();

    expect($result['already_fulfilled'])->toBeFalse()
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->successfulPayment)->not->toBeNull();
});

it('refuses a payment the provider says did not succeed', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'failed',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    expect(fn (): array => ($this->fulfil)()->handle($payment))
        ->toThrow(PaymentNotAcceptable::class, 'not success');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('refuses a payment for the wrong amount', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct(550_000));
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        // A cedi short. Not "close enough".
        'amount' => $payment->amount_minor - 100,
        'currency' => 'GHS',
    ]);

    expect(fn (): array => ($this->fulfil)()->handle($payment))
        ->toThrow(PaymentNotAcceptable::class, 'against the');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0);
});

it('refuses a payment in the wrong currency', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'USD',
    ]);

    expect(fn (): array => ($this->fulfil)()->handle($payment))
        ->toThrow(PaymentNotAcceptable::class, 'USD');
});

it('refuses a payment for a different reference', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => 'AIC-P-SOMEONE-ELSE',
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    expect(fn (): array => ($this->fulfil)()->handle($payment))
        ->toThrow(PaymentNotAcceptable::class, 'SOMEONE-ELSE');
});

/*
 * The heart of it: the provider is asked, and the answer is what counts.
 */
it('never fulfils without asking the provider', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    // The provider is unreachable. Nothing is assumed in the customer's
    // favour or ours.
    fakeHttp(['api.paystack.co/transaction/verify/*' => Http::response([], 500)]);

    expect(fn (): array => ($this->fulfil)()->handle($payment))
        ->toThrow(PaymentGatewayError::class);

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('records what the provider reported about the payment', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
        'id' => 998877,
        'channel' => 'mobile_money',
    ]);

    ($this->fulfil)()->handle($payment);

    $payment->refresh();

    expect($payment->status)->toBe(OrderPaymentStatus::Success)
        ->and($payment->provider_transaction_id)->toBe(998877)
        ->and($payment->provider_channel)->toBe('mobile_money')
        ->and($payment->paid_at)->not->toBeNull();
});

// ------------------------------------------------------------ Idempotency

it('fulfils once however many times the same payment is confirmed', function (): void {
    $product = stockedProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $first = payOrder($order, $payment);
    $second = payOrder($order, $payment);
    $third = payOrder($order, $payment);

    expect($first['already_fulfilled'])->toBeFalse()
        ->and($second['already_fulfilled'])->toBeTrue()
        ->and($third['already_fulfilled'])->toBeTrue()
        // One sale, not three.
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and($order->fresh()->transitions()->where('to_status', OrderStatus::Paid)->count())->toBe(1);
});

// ------------------------------------------------------------- Inventory

it('sells the held unit on a plain catalog purchase', function (): void {
    $product = stockedProduct(550_000, 1);
    $order = buyNowCheckout(bidder(), $product);

    expect($product->fresh()->stock_reserved)->toBe(1);

    payOrder($order);

    $product->refresh();

    // One unit gone, not two: the sale nets against the reservation.
    expect($product->stock_on_hand)->toBe(0)
        ->and($product->stock_reserved)->toBe(0)
        ->and($order->fresh()->holds_reservation)->toBeFalse();
});

it('records the sale against the order', function (): void {
    $product = stockedProduct();
    $order = buyNowCheckout(bidder(), $product);

    payOrder($order);

    $sale = InventoryTransaction::where('type', InventoryTransactionType::Sale)->first();

    expect($sale?->reference_type)->toBe(Order::class)
        ->and($sale?->reference_id)->toBe($order->id)
        ->and($sale?->quantity_delta)->toBe(-1);
});

it('creates no sale when the payment is not verified', function (): void {
    $product = stockedProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'abandoned',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    try {
        ($this->fulfil)()->handle($payment);
    } catch (PaymentNotAcceptable) {
        // Expected.
    }

    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0)
        // Still held for the customer, who may yet pay.
        ->and($product->fresh()->stock_reserved)->toBe(1);
});

// -------------------------------------------------- Auction-linked Buy Now

it('ends the auction when an auction-linked Buy Now is paid', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder();

    $order = buyNowCheckout($buyer, $product, $auction);
    payOrder($order);

    $auction->refresh();

    expect($auction->status)->toBe(AuctionStatus::Settled)
        ->and($auction->closure_reason)->toBe(AuctionClosureReason::BuyNow)
        ->and($auction->buy_now_user_id)->toBe($buyer->id)
        // Never a bid winner. A constraint refuses a row claiming both.
        ->and($auction->winner_user_id)->toBeNull()
        ->and($auction->winning_bid_id)->toBeNull();
});

it('does not make the standing highest bidder the winner', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $leader = bidder(2_000);
    $buyer = bidder();

    placeBid($auction, $leader, 900);

    $order = buyNowCheckout($buyer, $product, $auction);
    payOrder($order);

    expect($auction->fresh()->winner_user_id)->toBeNull()
        // The leader's credits are still gone.
        ->and(creditWalletFor($leader)->fresh()->balance)->toBe(1_100);
});

it('honours the discount frozen at checkout, not one recomputed at payment', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder(2_000);

    placeBid($auction, $buyer, 150);
    $order = buyNowCheckout($buyer, $product, $auction);

    expect($order->total_minor)->toBe(535_000);

    // The buyer bids more after opening the checkout. That does not
    // retroactively reduce the price they already agreed to and are paying.
    placeBid($auction->fresh(), $buyer, 300);

    payOrder($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->fresh()->total_minor)->toBe(535_000);
});

it('sells exactly one unit through an auction Buy Now', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    payOrder(buyNowCheckout(bidder(), $product, $auction));

    $product->refresh();

    expect($product->stock_on_hand)->toBe(0)
        ->and($product->stock_reserved)->toBe(0)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

// ------------------------------------------------------------- Settlement

it('settles the auction when the winner pays', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);

    placeBid($auction, $winner, 180);
    $this->close->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);
    payOrder($order);

    $auction->refresh();

    expect($auction->status)->toBe(AuctionStatus::Settled)
        ->and($auction->closure_reason)->toBe(AuctionClosureReason::HighestBid)
        ->and($auction->winner_user_id)->toBe($winner->id)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('charges the settlement amount and not the bid or the Buy Now price', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);

    placeBid($auction, $winner, 180);
    $this->close->handle($auction, force: true);

    $payment = initializePayment(settlementCheckout($auction->fresh(), $winner));

    expect($payment->amount_minor)->toBe(10_000)
        ->and($payment->amount_minor)->not->toBe(550_000)
        ->and($payment->amount_minor)->not->toBe(18_000);
});

it('consumes no credits when a winner settles', function (): void {
    $auction = liveAuction(settlementMinor: 10_000);
    $winner = bidder(1_000);

    placeBid($auction, $winner, 250);
    $this->close->handle($auction, force: true);

    payOrder(settlementCheckout($auction->fresh(), $winner));

    // 250 consumed at bid time, and not a credit since.
    expect(creditWalletFor($winner)->fresh()->balance)->toBe(750)
        ->and(CreditTransaction::where('type', CreditTransactionType::BidDebit)->count())
        ->toBe(1);
});

it('returns no credits when a winner settles', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);

    placeBid($auction, $winner, 250);
    $this->close->handle($auction, force: true);

    payOrder(settlementCheckout($auction->fresh(), $winner));

    expect(CreditTransaction::whereIn('type', [
        CreditTransactionType::Refund,
        CreditTransactionType::Reversal,
    ])->count())->toBe(0);
});

it('leaves the winning bid exactly as it was', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);

    $bid = placeBid($auction, $winner, 180);
    $this->close->handle($auction, force: true);

    payOrder(settlementCheckout($auction->fresh(), $winner));

    expect($bid->fresh()->amount_credits)->toBe(180)
        ->and($auction->fresh()->winning_bid_id)->toBe($bid->id);
});

it('sells the unit the auction was holding', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);

    placeBid($auction, $winner, 100);
    $this->close->handle($auction, force: true);

    expect($product->fresh()->stock_reserved)->toBe(1);

    payOrder(settlementCheckout($auction->fresh(), $winner));

    $product->refresh();

    expect($product->stock_on_hand)->toBe(0)
        ->and($product->stock_reserved)->toBe(0);
});

// ------------------------------------------------- Paid orders are frozen

it('refuses to change the commercial figures of a paid order', function (string $column, int $value): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    payOrder($order);

    $paid = $order->fresh();
    $paid->{$column} = $value;

    expect(fn (): bool => $paid->save())
        ->toThrow(OrderRecordIsFrozen::class);
})->with([
    'total' => ['total_minor', 1],
    'subtotal' => ['subtotal_minor', 1],
    'discount' => ['discount_minor', 1],
    'delivery' => ['delivery_minor', 1],
]);

it('refuses a change to a paid order made straight through the database', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct(550_000));
    payOrder($order);

    expect(fn () => DB::table('orders')->where('id', $order->id)
        ->update(['subtotal_minor' => 1, 'total_minor' => 1]))
        ->toThrow(QueryException::class, 'historical record');

    expect($order->fresh()->total_minor)->toBe(550_000);
});

it('still allows a paid order to be advanced operationally', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    payOrder($order);

    $this->orders->advance($order->fresh(), OrderStatus::Processing);
    $this->orders->advance($order->fresh(), OrderStatus::Fulfilled);

    expect($order->fresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($order->fresh()->fulfilled_at)->not->toBeNull();
});

it('offers no way at all to mark an order paid by hand', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    // The only operational advance permitted is forwards from Paid. There is
    // no method, anywhere, that asserts money arrived.
    expect(fn (): Order => $this->orders->advance($order, OrderStatus::Paid))
        ->toThrow(InvalidOrderTransition::class, 'verified payment');
});

// ------------------------------------------------------- Blocked fulfilment

/*
 * A genuine ambiguity, handled honestly rather than swallowed. The customer's
 * money arrived; the thing they were buying did not survive long enough to be
 * given to them.
 */
it('records a paid order it could not complete rather than losing it', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $slowBuyer = bidder();
    $quickBuyer = bidder();

    $slowOrder = buyNowCheckout($slowBuyer, $product, $auction);
    $quickOrder = buyNowCheckout($quickBuyer, $product, $auction);

    // The quick buyer pays first and takes the product.
    payOrder($quickOrder);

    // The slow buyer's payment then succeeds against an auction that is gone.
    payOrder($slowOrder);

    $slow = $slowOrder->fresh();

    expect($slow->status)->toBe(OrderStatus::Paid)
        // The money is recorded, because it is real.
        ->and($slow->successfulPayment)->not->toBeNull()
        // And so is the fact that nothing could be delivered.
        ->and($slow->isFulfilmentBlocked())->toBeTrue()
        ->and($slow->fulfilment_blocked_reason)->toContain('already been bought');

    // Still exactly one sale.
    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

/*
 * This asserted a throw until the late-payment policy was ruled on. Throwing
 * returned a 5xx and had Paystack retrying the same delivery forever against
 * an order that could never accept it.
 *
 * The payment succeeded and is recorded as such. The order keeps its terminal
 * status: a payment success records a financial event, it does not resurrect
 * an order that was already closed.
 */
it('records but does not fulfil a payment against a cancelled checkout', function (): void {
    $product = stockedProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $this->orders->cancel($order->fresh(), 'Customer changed their mind.');

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    ($this->fulfil)()->handle($payment->fresh());

    $order = $order->fresh();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->status)->not->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->toBeNull()
        ->and($order->isFulfilmentBlocked())->toBeTrue()
        // The money is real, so the attempt records that it succeeded.
        ->and($payment->fresh()->status)->toBe(OrderPaymentStatus::Success)
        // Nothing was delivered, and cancelling had already released the unit.
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0)
        ->and($product->fresh()->availableStock())->toBe(1);
});

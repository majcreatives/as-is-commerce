<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\CreditTransactionType;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\CreditTransaction;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/*
 * The locked policy for a payment that succeeds when it can no longer buy
 * anything.
 *
 * THE PRINCIPLE, and everything here follows from it:
 *
 *     Payment success records the financial event. It does not resurrect an
 *     order that was already closed.
 *
 * So there are two shapes, not one:
 *
 *   Inventory conflict   The checkout was still open and still payable; the
 *                        unit went to somebody else first. The order becomes
 *                        Paid, and fulfilment is blocked.
 *
 *   Late payment         The checkout had already expired or been cancelled
 *                        before the money landed. The order keeps its terminal
 *                        status, and fulfilment is blocked.
 *
 * In both, the payment attempt itself is recorded as successful, because it
 * was. Neither refunds anything: recovering a customer's money is the Refund
 * and Recovery stage's work, and inventing it here would be inventing a policy.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->orders = app(OrderLifecycle::class);
    $this->close = app(CloseAuction::class);
});

/**
 * A plain catalog product with stock, ready to be bought.
 */
function policyProduct(int $priceMinor = 550_000, int $stock = 1): Product
{
    $product = Product::factory()->active()->pricedAt($priceMinor)->create();
    app(InventoryService::class)->initialStock($product, $stock);

    return $product->fresh();
}

// ----------------------------------------------- 1. The ordinary success

it('pays and fulfils an order that was still open', function (): void {
    $product = policyProduct();
    $order = buyNowCheckout(bidder(), $product);

    payOrder($order);

    $order = $order->fresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        // Nothing blocked: the product actually changed hands.
        ->and($order->isFulfilmentBlocked())->toBeFalse()
        ->and($order->successfulPayment)->not->toBeNull()
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

// -------------------------------------------- 2. Inventory conflict

/*
 * The checkout was open and payable throughout. Somebody else simply got the
 * unit first. The order reaches Paid, because it was.
 */
it('keeps an order Paid and blocked when another transaction took the unit', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $slow = buyNowCheckout(bidder(), $product->fresh(), $auction->fresh());
    $quick = buyNowCheckout(bidder(), $product->fresh(), $auction->fresh());

    payOrder($quick);
    payOrder($slow);

    $slow = $slow->fresh();

    expect($slow->status)->toBe(OrderStatus::Paid)
        ->and($slow->paid_at)->not->toBeNull()
        ->and($slow->isFulfilmentBlocked())->toBeTrue()
        ->and($slow->fulfilment_blocked_reason)->toContain('already been bought')
        ->and($slow->successfulPayment?->status)->toBe(OrderPaymentStatus::Success)
        // One unit, one sale.
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

// ------------------------------------------------- 3. Paid after expiry

/*
 * The checkout had already expired. Its terminal status stands: a successful
 * payment records a financial event, and does not reopen a closed order.
 */
it('leaves an expired checkout expired and blocked when payment lands late', function (): void {
    $product = policyProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired);

    payOrder($order->fresh(), $payment->fresh());

    $order = $order->fresh();

    expect($order->status)->toBe(OrderStatus::PaymentExpired)
        // Emphatically not resurrected.
        ->and($order->status)->not->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->toBeNull()
        ->and($order->isFulfilmentBlocked())->toBeTrue()
        ->and($order->fulfilment_blocked_reason)->toContain('payment_expired')
        // The money is still recorded, because it is real.
        ->and($payment->fresh()->status)->toBe(OrderPaymentStatus::Success)
        ->and($payment->fresh()->paid_at)->not->toBeNull()
        // And nothing was delivered.
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0)
        ->and($product->fresh()->availableStock())->toBe(1);
});

// -------------------------------------------- 4. Paid after cancellation

it('leaves a cancelled checkout cancelled and blocked when payment lands late', function (): void {
    $product = policyProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $this->orders->cancel($order->fresh(), 'Customer changed their mind.');
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);

    payOrder($order->fresh(), $payment->fresh());

    $order = $order->fresh();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->status)->not->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->toBeNull()
        ->and($order->isFulfilmentBlocked())->toBeTrue()
        ->and($order->fulfilment_blocked_reason)->toContain('cancelled')
        ->and($payment->fresh()->status)->toBe(OrderPaymentStatus::Success)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0)
        // Cancelling released the unit, and a late payment does not take it
        // back off the shelf.
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('leaves a forfeited auction settlement cancelled and blocked', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $order = $this->close->handle($auction, force: true)->settlementOrder;
    $payment = initializePayment($order);

    $this->travel(3)->hours();
    $this->artisan('auctions:tick');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);

    payOrder($order->fresh(), $payment->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->isFulfilmentBlocked())->toBeTrue()
        ->and($payment->fresh()->status)->toBe(OrderPaymentStatus::Success)
        ->and($product->fresh()->availableStock())->toBe(1)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0);
});

// ------------------------------------- 5. Duplicate webhooks, late cases

/*
 * The retry that used to be a storm. Throwing here returned a 5xx, and
 * Paystack retried the same delivery forever against an order that could never
 * accept it. Acknowledging is both truthful and terminal.
 */
it('acknowledges a late payment webhook rather than asking for a retry', function (): void {
    $product = policyProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    postOrderWebhook(paystackChargePayload($payment->provider_reference, $payment->amount_minor))
        ->assertOk();

    expect($order->fresh()->isFulfilmentBlocked())->toBeTrue();
});

it('is harmless when the same late webhook arrives repeatedly', function (): void {
    $product = policyProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
        'id' => 4242,
    ]);

    $body = paystackChargePayload($payment->provider_reference, $payment->amount_minor, transactionId: 4242);

    foreach (range(1, 5) as $ignored) {
        postOrderWebhook($body)->assertOk();
    }

    expect(PaymentWebhookEvent::count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and(OrderPayment::successful()->count())->toBe(1)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0);
});

it('is harmless when a late payment is fulfilled repeatedly by hand', function (): void {
    $product = policyProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());

    foreach (range(1, 5) as $ignored) {
        payOrder($order->fresh(), $payment->fresh());
    }

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and($order->fresh()->isFulfilmentBlocked())->toBeTrue()
        ->and(OrderPayment::where('order_id', $order->id)->successful()->count())->toBe(1);
});

// -------------------------------- 6. No duplicate effects, any late case

it('creates no duplicate order, transition or sale from a late payment', function (): void {
    $product = policyProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());

    $transitionsBefore = $order->fresh()->transitions()->count();

    foreach (range(1, 3) as $ignored) {
        payOrder($order->fresh(), $payment->fresh());
    }

    expect(Order::count())->toBe(1)
        // A blocked reason is not a state change, so the history does not grow.
        ->and($order->fresh()->transitions()->count())->toBe($transitionsBefore)
        ->and($order->fresh()->transitions()->where('to_status', OrderStatus::Paid)->count())->toBe(0)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0)
        ->and(OrderPayment::count())->toBe(1);
});

it('creates no duplicate effects from a repeated inventory-conflict payment', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $slow = buyNowCheckout(bidder(), $product->fresh(), $auction->fresh());
    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));

    $slowPayment = initializePayment($slow);

    foreach (range(1, 4) as $ignored) {
        payOrder($slow->fresh(), $slowPayment->fresh());
    }

    expect($slow->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($slow->fresh()->transitions()->where('to_status', OrderStatus::Paid)->count())->toBe(1)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

// ------------------------------------------ 7. No refund, in any case

it('refunds nothing automatically in any blocked case', function (string $case): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();

    if ($case === 'conflict') {
        $auction = liveAuction(product: $product);
        $slow = buyNowCheckout(bidder(), $product->fresh(), $auction->fresh());
        payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));
        payOrder($slow);
        $order = $slow;
    } else {
        app(InventoryService::class)->initialStock($product, 1);
        $order = buyNowCheckout(bidder(), $product->fresh());
        $payment = initializePayment($order);

        $this->travel(2)->hours();

        $case === 'expired'
            ? $this->orders->expire($order->fresh())
            : $this->orders->cancel($order->fresh(), 'Changed my mind.');

        payOrder($order->fresh(), $payment->fresh());
    }

    // No cash movement of any kind, in either ledger, in either direction.
    expect(DB::table('cash_transactions')->count())->toBe(0)
        ->and(CreditTransaction::whereIn('type', [
            CreditTransactionType::Refund,
            CreditTransactionType::Reversal,
        ])->count())->toBe(0)
        // The successful payment stays exactly as it was: nothing reverses it.
        ->and($order->fresh()->payments()->successful()->count())->toBe(1)
        ->and($order->fresh()->isFulfilmentBlocked())->toBeTrue();
})->with(['conflict', 'expired', 'cancelled']);

it('has no refund machinery to invoke', function (): void {
    // Structural, and the point of it: there is nothing to call. A future
    // Refund and Recovery stage introduces this deliberately; until then the
    // absence is the policy.
    expect(class_exists('App\\Domain\\Orders\\Actions\\RefundOrderPayment'))->toBeFalse()
        ->and(method_exists(OrderLifecycle::class, 'refund'))->toBeFalse()
        ->and(method_exists(App\Domain\Payments\Contracts\PaymentGateway::class, 'refundTransaction'))
        ->toBeFalse();
});

// ------------------------------------------------- The queue it lands in

it('puts every blocked case in front of an administrator', function (): void {
    $product = policyProduct();
    $order = buyNowCheckout(bidder(), $product);
    $payment = initializePayment($order);

    $this->travel(2)->hours();
    $this->orders->expire($order->fresh());
    payOrder($order->fresh(), $payment->fresh());

    // The same queue the inventory-conflict case lands in, so operations has
    // one place to look rather than two.
    expect(Order::query()->blocked()->count())->toBe(1);

    Livewire\Livewire::actingAs(userWithRole('admin'))
        ->test(App\Livewire\Admin\Orders\OrderManager::class)
        ->assertSee('needs attention')
        ->set('blocked', true)
        ->assertSee($order->order_number);
});

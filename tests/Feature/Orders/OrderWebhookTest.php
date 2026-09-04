<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Payments\Actions\InitializeCreditPurchase;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\CreditPackage;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use Illuminate\Support\Facades\Http;

/*
 * Webhooks for product payments, through the Stage 4 endpoint.
 *
 * The endpoint is unchanged: same signature check, same store-before-process,
 * same duplicate defence. What is new is that a reference may now resolve to
 * an order payment as well as to a credit purchase.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);
});

/**
 * An order with an open payment, ready for a webhook to arrive.
 *
 * @return array{Order, OrderPayment, Product}
 */
function orderAwaitingWebhook(int $priceMinor = 550_000): array
{
    $product = Product::factory()->active()->pricedAt($priceMinor)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    $payment = initializePayment($order);

    return [$order, $payment, $product];
}

// ---------------------------------------------------------------- Security

it('rejects a webhook with no signature', function (): void {
    [, $payment] = orderAwaitingWebhook();

    $raw = json_encode(paystackChargePayload($payment->provider_reference, $payment->amount_minor));

    $this->call('POST', route('webhooks.paystack'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
        ->assertStatus(401);

    // Not even stored: an unsigned request is from an unknown party.
    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('rejects a webhook with a signature from the wrong secret', function (): void {
    [$order, $payment] = orderAwaitingWebhook();

    $raw = json_encode(paystackChargePayload($payment->provider_reference, $payment->amount_minor));

    $this->call(
        'POST', route('webhooks.paystack'), [], [], [],
        ['HTTP_X_PAYSTACK_SIGNATURE' => paystackSignature($raw, 'the-wrong-secret'),
            'CONTENT_TYPE' => 'application/json'],
        $raw,
    )->assertStatus(401);

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(PaymentWebhookEvent::count())->toBe(0);
});

// ------------------------------------------------------------- Fulfilment

it('completes an order on a verified successful charge', function (): void {
    [$order, $payment, $product] = orderAwaitingWebhook();

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
        'id' => 12345,
    ]);

    postOrderWebhook(paystackChargePayload($payment->provider_reference, $payment->amount_minor))
        ->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

it('ties the stored event to the payment it resolved to', function (): void {
    [, $payment] = orderAwaitingWebhook();

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    postOrderWebhook(paystackChargePayload($payment->provider_reference, $payment->amount_minor));

    $event = PaymentWebhookEvent::first();

    expect($event?->order_payment_id)->toBe($payment->id)
        // Never both: a reference belongs to one or the other.
        ->and($event?->credit_purchase_id)->toBeNull()
        ->and($event?->processing_status)->toBe(WebhookProcessingStatus::Processed);
});

/*
 * The payload says success. That is a claim, and the platform does not act on
 * claims.
 */
it('does not complete an order when the provider disagrees with the payload', function (): void {
    [$order, $payment, $product] = orderAwaitingWebhook();

    // The body claims success; asking Paystack says otherwise.
    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'failed',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    postOrderWebhook(paystackChargePayload($payment->provider_reference, $payment->amount_minor))
        ->assertStatus(500);

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($product->fresh()->stock_on_hand)->toBe(1)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0);
});

it('does not complete an order when the amount does not match', function (): void {
    [$order, $payment] = orderAwaitingWebhook(550_000);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        // The payload will claim the right amount; the provider says less.
        'amount' => 100,
        'currency' => 'GHS',
    ]);

    postOrderWebhook(paystackChargePayload($payment->provider_reference, 550_000))
        ->assertStatus(500);

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

/*
 * A 5xx is deliberate: Paystack retries, the event is already stored, and a
 * transient fault must not silently strand a paying customer.
 */
it('asks the provider to retry when processing fails', function (): void {
    [, $payment] = orderAwaitingWebhook();

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'failed',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    postOrderWebhook(paystackChargePayload($payment->provider_reference, $payment->amount_minor))
        ->assertStatus(500);

    expect(PaymentWebhookEvent::first()?->processing_status)
        ->toBe(WebhookProcessingStatus::Failed);
});

// ------------------------------------------------------------- Duplicates

it('is harmless when the same event arrives repeatedly', function (): void {
    [$order, $payment, $product] = orderAwaitingWebhook();

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
        'id' => 555,
    ]);

    $body = paystackChargePayload($payment->provider_reference, $payment->amount_minor, transactionId: 555);

    foreach (range(1, 4) as $ignored) {
        postOrderWebhook($body)->assertOk();
    }

    expect(PaymentWebhookEvent::count())->toBe(1)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and($order->fresh()->transitions()->where('to_status', OrderStatus::Paid)->count())->toBe(1);
});

it('fulfils once when a webhook and a callback both arrive', function (): void {
    [$order, $payment, $product] = orderAwaitingWebhook();

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    // The webhook lands first.
    postOrderWebhook(paystackChargePayload($payment->provider_reference, $payment->amount_minor))
        ->assertOk();

    // Then the customer's browser comes back.
    $this->actingAs($order->user)
        ->get(route('checkout.callback', ['reference' => $payment->provider_reference]))
        ->assertOk()
        ->assertSee('Payment confirmed');

    // One sale, whichever order they arrived in.
    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

it('fulfils once when the callback arrives before the webhook', function (): void {
    [$order, $payment, $product] = orderAwaitingWebhook();

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    $this->actingAs($order->user)
        ->get(route('checkout.callback', ['reference' => $payment->provider_reference]))
        ->assertOk();

    postOrderWebhook(paystackChargePayload($payment->provider_reference, $payment->amount_minor))
        ->assertOk();

    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

// ------------------------------------------------------------ Other events

it('closes out an order when the provider reports a failed charge', function (): void {
    [$order, $payment, $product] = orderAwaitingWebhook();

    postOrderWebhook(paystackChargePayload(
        $payment->provider_reference,
        $payment->amount_minor,
        overrides: ['event' => 'charge.failed', 'data' => ['status' => 'failed']],
    ))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentFailed)
        // The held unit goes back on sale.
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('records an unrelated reference without acting on it', function (): void {
    postOrderWebhook(paystackChargePayload('AIC-P-NOT-OURS', 10_000))->assertOk();

    expect(PaymentWebhookEvent::first()?->processing_status)
        ->toBe(WebhookProcessingStatus::Ignored);
});

it('refuses an event whose metadata names a different payment', function (): void {
    [, $payment] = orderAwaitingWebhook();

    postOrderWebhook(paystackChargePayload(
        $payment->provider_reference,
        $payment->amount_minor,
        overrides: ['data' => ['metadata' => ['order_payment_id' => $payment->id + 999]]],
    ))->assertStatus(500);

    expect(PaymentWebhookEvent::first()?->processing_status)
        ->toBe(WebhookProcessingStatus::Failed);
});

// ------------------------------------------------ Credit purchases still work

it('still fulfils a credit purchase through the same endpoint', function (): void {
    $customer = userWithRole('customer');

    $package = CreditPackage::factory()->priced(500, 4_500)->create();

    fakeHttp([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/x', 'access_code' => 'a'],
        ]),
    ]);

    $purchase = app(InitializeCreditPurchase::class)
        ->handle($customer, $package)['purchase'];

    fakePaystackVerify([
        'reference' => $purchase->provider_reference,
        'status' => 'success',
        'amount' => $purchase->amount_minor,
        'currency' => 'GHS',
    ]);

    postOrderWebhook(paystackChargePayload($purchase->provider_reference, $purchase->amount_minor))
        ->assertOk();

    // Stage 4 is untouched: the same endpoint still grants credits.
    expect(creditWalletFor($customer)->fresh()->balance)->toBe(500)
        ->and(PaymentWebhookEvent::first()?->credit_purchase_id)->toBe($purchase->id)
        ->and(PaymentWebhookEvent::first()?->order_payment_id)->toBeNull();
});

// -------------------------------------------------------------- Callback

it('does not confirm an order because a browser returned', function (): void {
    [$order, $payment] = orderAwaitingWebhook();

    // The customer returns, but Paystack has not settled the payment.
    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'pending',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    $this->actingAs($order->user)
        ->get(route('checkout.callback', ['reference' => $payment->provider_reference]))
        ->assertOk()
        ->assertSee('not confirmed yet')
        ->assertDontSee('Payment confirmed');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('does not show one customer another customer payment', function (): void {
    [, $payment] = orderAwaitingWebhook();

    $this->actingAs(bidder())
        ->get(route('checkout.callback', ['reference' => $payment->provider_reference]))
        ->assertOk()
        ->assertSee('could not find that payment');
});

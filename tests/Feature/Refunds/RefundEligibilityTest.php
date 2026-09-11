<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Refunds\Actions\RequestRefund;
use App\Domain\Refunds\Exceptions\RefundNotAllowed;
use App\Domain\Refunds\Services\RefundCalculator;
use App\Domain\Refunds\Services\RefundEligibility;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderStatus;
use App\Models\Product;
use App\Models\Refund;

/*
 * Whether a refund may happen at all, and what it may be for.
 *
 * The two questions are separate and both are answered on the server. A hidden
 * button is not a rule; these are the rules.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->admin = userWithRole('admin');
    $this->request = app(RequestRefund::class);
    $this->eligibility = app(RefundEligibility::class);
    $this->calculator = app(RefundCalculator::class);
});

// -------------------------------------------------------- What is eligible

it('allows a refund on a paid order that could not be delivered', function (): void {
    $order = blockedPaidOrder();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->isFulfilmentBlocked())->toBeTrue();

    $refund = requestRefund($order);

    expect($refund->amount_minor)->toBe($order->total_minor)
        ->and($refund->order_id)->toBe($order->id);
});

it('allows a refund when a payment landed after the checkout expired', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    $payment = initializePayment($order);

    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire($order->fresh());

    payOrder($order->fresh(), $payment->fresh());

    $order = $order->fresh();

    // The order kept the status it closed with. The money is still real.
    expect($order->status)->toBe(OrderStatus::PaymentExpired)
        ->and($order->isFulfilmentBlocked())->toBeTrue();

    $refund = requestRefund($order);

    expect($refund->amount_minor)->toBe($payment->fresh()->amount_minor);
});

it('allows a refund when a payment landed after cancellation', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    $payment = initializePayment($order);

    app(OrderLifecycle::class)->cancel($order->fresh(), 'Customer changed their mind.');

    payOrder($order->fresh(), $payment->fresh());

    $order = $order->fresh();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->isFulfilmentBlocked())->toBeTrue();

    expect(requestRefund($order)->amount_minor)->toBe($payment->fresh()->amount_minor);
});

// ---------------------------------------------------- What is not eligible

it('refuses a refund on a payment that never succeeded', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    initializePayment($order);

    expect(fn (): Refund => requestRefund($order->fresh()))
        ->toThrow(RefundNotAllowed::class);

    expect(Refund::count())->toBe(0);
});

it('refuses a refund on a healthy paid order', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    payOrder($order);

    // Nothing went wrong: the customer is getting their item. Refunding here
    // would give away the money and the stock, because a refund restores no
    // inventory.
    expect($order->fresh()->isFulfilmentBlocked())->toBeFalse()
        ->and(fn (): Refund => requestRefund($order->fresh()))
        ->toThrow(RefundNotAllowed::class, 'not a recovery case');
});

it('refuses a refund on an order already delivered', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    payOrder($order);

    app(OrderLifecycle::class)->advance($order->fresh(), OrderStatus::Fulfilled, $this->admin);

    expect(fn (): Refund => requestRefund($order->fresh()))
        ->toThrow(RefundNotAllowed::class, 'return');
});

it('refuses a second refund while one is already in progress', function (): void {
    $order = blockedPaidOrder();

    requestRefund($order);

    expect(fn (): Refund => requestRefund($order->fresh()))
        ->toThrow(RefundNotAllowed::class, 'already in progress');

    expect(Refund::count())->toBe(1);
});

// ------------------------------------------------------------- The amount

it('refuses a refund larger than the payment', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    $tooMuch = Money::fromMinor($payment->amount_minor + 100);

    expect(fn (): Refund => requestRefund($order, $tooMuch))
        ->toThrow(RefundNotAllowed::class, 'may still be returned');

    expect(Refund::count())->toBe(0);
});

it('refuses a refund of nothing', function (): void {
    $order = blockedPaidOrder();

    expect(fn (): Refund => requestRefund($order, Money::zero()))
        ->toThrow(RefundNotAllowed::class, 'positive');
});

/*
 * A negative amount cannot even be constructed as a request: Money is a signed
 * value object, so the check is that the domain refuses it rather than storing
 * it. The database refuses it too -- a CHECK constraint on amount_minor.
 */
it('refuses a negative refund', function (): void {
    $order = blockedPaidOrder();

    expect(fn (): Refund => requestRefund($order, Money::fromMinor(-500)))
        ->toThrow(RefundNotAllowed::class, 'positive');
});

it('reports the refundable amount as the payment less what has gone back', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    expect($this->calculator->refundable($payment)->minor)->toBe($payment->amount_minor)
        ->and($this->calculator->refunded($payment)->minor)->toBe(0);

    // GH₵100 of it returned, whatever the payment was.
    Refund::factory()->succeeded()->create([
        'order_id' => $order->id,
        'order_payment_id' => $payment->id,
        'amount_minor' => 10_000,
        'requested_by' => $this->admin->id,
    ]);

    expect($this->calculator->refunded($payment)->minor)->toBe(10_000)
        ->and($this->calculator->refundable($payment)->minor)
        ->toBe($payment->amount_minor - 10_000);
});

it('cannot be refunded past the original payment in several goes', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    // Nearly all of it already back.
    Refund::factory()->succeeded()->create([
        'order_id' => $order->id,
        'order_payment_id' => $payment->id,
        'amount_minor' => $payment->amount_minor - 5_000,
        'requested_by' => $this->admin->id,
    ]);

    // GH₵50 remains, and GH₵60 is refused.
    expect($this->calculator->refundable($payment)->minor)->toBe(5_000)
        ->and(fn (): Refund => requestRefund($order->fresh(), Money::fromMinor(6_000)))
        ->toThrow(RefundNotAllowed::class);

    // GH₵50 is allowed, and then nothing is.
    $refund = requestRefund($order->fresh(), Money::fromMinor(5_000));

    expect($refund->amount_minor)->toBe(5_000)
        ->and($this->calculator->refundable($payment->fresh())->minor)->toBe(0);
});

/*
 * The defence that matters. Subtracting only succeeded refunds would let two
 * attempts for most of a payment coexist on the theory that only one settles.
 */
it('counts an in-flight refund against what may still be refunded', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    requestRefund($order);

    expect($this->calculator->refundable($payment->fresh())->minor)->toBe(0)
        // And nothing has actually gone back yet, which is a different figure.
        ->and($this->calculator->refunded($payment->fresh())->minor)->toBe(0);
});

it('releases the amount again when an attempt fails', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    Refund::factory()->failed()->create([
        'order_id' => $order->id,
        'order_payment_id' => $payment->id,
        'amount_minor' => $payment->amount_minor,
        'requested_by' => $this->admin->id,
    ]);

    // A refund that returned nothing holds nothing back.
    expect($this->calculator->refundable($payment->fresh())->minor)->toBe($payment->amount_minor);
});

// ------------------------------------------------------------ Idempotency

it('produces one refund however many times the request is retried', function (): void {
    $order = blockedPaidOrder();

    // The same operator pressing the button again -- a retry of the same key
    // from the same person resolves to the refund they already asked for.
    $admin = userWithRole('admin');
    $first = requestRefund($order, key: 'operator-double-click', actor: $admin);
    $second = requestRefund($order->fresh(), key: 'operator-double-click', actor: $admin);

    expect($second->id)->toBe($first->id)
        ->and(Refund::count())->toBe(1);
});

<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Contracts\FulfilmentHandoff;
use App\Domain\Orders\Exceptions\PaymentNotAcceptable;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Delivery;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\Http;

/*
 * What the order payment path leaves behind when something breaks mid-flight.
 *
 * The credit purchase path already proves this for itself: a ledger failure
 * unwinds the cash entries with it, and the purchase stays visibly outstanding
 * so a retry can put it right. The order path -- Buy Now, and an auction
 * winner settling -- had no equivalent, and it does strictly more work: it
 * marks the attempt successful, sells a unit of stock, moves the order to Paid
 * and opens a delivery, all inside one transaction.
 *
 * THE PROPERTY BEING PROVEN IS NOT "IT RECOVERS". It is that every failure
 * lands in a state somebody can read: either the whole thing happened or none
 * of it did, never a payment recorded against stock that never moved. A retry
 * is then a decision, not a repair.
 *
 * Nothing here changes behaviour. These tests describe what the existing code
 * already does under failure, which was previously untested and therefore free
 * to change without anybody noticing.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->product = stockedProduct();
    $this->buyer = bidder();
    $this->order = buyNowCheckout($this->buyer, $this->product->fresh());
    $this->payment = initializePayment($this->order);

    fakePaystackVerify([
        'reference' => $this->payment->provider_reference,
        'status' => 'success',
        'amount' => $this->payment->amount_minor,
        'currency' => $this->payment->currency,
        'id' => 987654,
        'channel' => 'mobile_money',
        'paid_at' => now()->toIso8601String(),
    ]);
});

/**
 * Everything the fulfilment path could have written.
 *
 * @return array{payment_status: string, order_status: string, sales: int, stock_on_hand: int, stock_reserved: int, deliveries: int}
 */
function orderFulfilmentState(Order $order, OrderPayment $payment): array
{
    return [
        'payment_status' => $payment->fresh()->status->value,
        'order_status' => $order->fresh()->status->value,
        'sales' => InventoryTransaction::where('type', InventoryTransactionType::Sale)->count(),
        'stock_on_hand' => $order->fresh()->items->first()->product->fresh()->stock_on_hand,
        'stock_reserved' => $order->fresh()->items->first()->product->fresh()->stock_reserved,
        'deliveries' => Delivery::count(),
    ];
}

// ------------------------------------------- The sale itself fails

it('records nothing at all when the inventory sale throws', function (): void {
    // The provider confirmed the money. Then the stock movement fails.
    $this->partialMock(
        InventoryService::class,
        fn ($mock) => $mock->shouldReceive('recordSale')
            ->andThrow(new RuntimeException('Inventory unavailable.')),
    );

    expect(fn (): array => app(FulfillOrderPayment::class)->handle($this->payment->fresh()))
        ->toThrow(RuntimeException::class);

    $state = orderFulfilmentState($this->order, $this->payment);

    // The attempt is NOT left marked successful. Everything the transaction
    // touched went back, so there is no payment recorded against a sale that
    // never happened.
    expect($state['payment_status'])->not->toBe(OrderPaymentStatus::Success->value)
        ->and($state['order_status'])->not->toBe(OrderStatus::Paid->value)
        ->and($state['sales'])->toBe(0)
        // The unit is still held by the checkout, not sold and not released.
        ->and($state['stock_on_hand'])->toBe(1)
        ->and($state['stock_reserved'])->toBe(1)
        ->and($state['deliveries'])->toBe(0);
});

it('leaves the payment retryable after the inventory service recovers', function (): void {
    $this->partialMock(
        InventoryService::class,
        fn ($mock) => $mock->shouldReceive('recordSale')
            ->andThrow(new RuntimeException('Inventory unavailable.')),
    );

    try {
        app(FulfillOrderPayment::class)->handle($this->payment->fresh());
    } catch (RuntimeException) {
        // expected
    }

    // The service recovers. Rebinding rather than refreshing the application,
    // which would abandon the test transaction and leave its locks held.
    $this->app->forgetInstance(InventoryService::class);
    $this->app->bind(InventoryService::class, fn (): InventoryService => new InventoryService);

    app(FulfillOrderPayment::class)->handle($this->payment->fresh());

    $state = orderFulfilmentState($this->order, $this->payment);

    // The same verified payment now completes, exactly once.
    expect($state['payment_status'])->toBe(OrderPaymentStatus::Success->value)
        ->and($state['order_status'])->toBe(OrderStatus::Paid->value)
        ->and($state['sales'])->toBe(1)
        ->and($state['stock_on_hand'])->toBe(0)
        ->and($state['stock_reserved'])->toBe(0);
});

// ------------------------------------ Opening the delivery fails

it('records nothing when opening the delivery throws', function (): void {
    // Delivery creation runs inside the same transaction as the sale, so a
    // failure here unwinds a payment the provider has genuinely taken. That is
    // deliberate and it is the safe direction -- nothing inconsistent is
    // stored, and the next webhook delivery retries -- but it must be a known
    // property rather than an accident.
    $this->partialMock(
        FulfilmentHandoff::class,
        fn ($mock) => $mock->shouldReceive('openFor')
            ->andThrow(new RuntimeException('Delivery could not be opened.')),
    );

    expect(fn (): array => app(FulfillOrderPayment::class)->handle($this->payment->fresh()))
        ->toThrow(RuntimeException::class);

    $state = orderFulfilmentState($this->order, $this->payment);

    expect($state['payment_status'])->not->toBe(OrderPaymentStatus::Success->value)
        ->and($state['order_status'])->not->toBe(OrderStatus::Paid->value)
        // The sale went back with it. A sold unit and an unpaid order would be
        // the worst of both.
        ->and($state['sales'])->toBe(0)
        ->and($state['stock_on_hand'])->toBe(1)
        ->and($state['deliveries'])->toBe(0);
});

// ------------------------------------------- The provider misbehaves

it('records nothing when the provider cannot be reached', function (): void {
    fakeHttp(['api.paystack.co/transaction/verify/*' => Http::response([], 500)]);

    expect(fn (): array => app(FulfillOrderPayment::class)->handle($this->payment->fresh()))
        ->toThrow(PaymentGatewayError::class);

    $state = orderFulfilmentState($this->order, $this->payment);

    // Nothing is assumed in the customer's favour, and nothing against them.
    expect($state['payment_status'])->not->toBe(OrderPaymentStatus::Success->value)
        ->and($state['order_status'])->not->toBe(OrderStatus::Paid->value)
        ->and($state['sales'])->toBe(0);
});

it('records nothing when the provider answers with an unreadable body', function (): void {
    // A 200 with nothing usable in it. Read optimistically, this is exactly
    // how a platform hands over stock for a payment nobody made.
    fakeHttp([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['reference' => 'something-else', 'status' => 'success'],
        ]),
    ]);

    expect(fn (): array => app(FulfillOrderPayment::class)->handle($this->payment->fresh()))
        ->toThrow(PaymentGatewayError::class);

    expect(orderFulfilmentState($this->order, $this->payment)['sales'])->toBe(0);
});

it('records nothing when the provider reports a different transaction', function (): void {
    fakeHttp([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                // Somebody else's successful payment, for the right amount.
                'reference' => 'a-reference-for-another-order',
                'status' => 'success',
                'amount' => $this->payment->amount_minor,
                'currency' => $this->payment->currency,
            ],
        ]),
    ]);

    expect(fn (): array => app(FulfillOrderPayment::class)->handle($this->payment->fresh()))
        ->toThrow(PaymentNotAcceptable::class);

    $state = orderFulfilmentState($this->order, $this->payment);

    expect($state['payment_status'])->not->toBe(OrderPaymentStatus::Success->value)
        ->and($state['sales'])->toBe(0);
});

// ------------------------------------------------ Nothing is half-done

it('never leaves a successful payment against stock that did not move', function (): void {
    // The invariant all of the above serve, asserted directly: across every
    // failure mode, a payment marked successful and a sale must either both
    // exist or neither.
    foreach ([
        fn () => $this->partialMock(InventoryService::class, fn ($m) => $m->shouldReceive('recordSale')->andThrow(new RuntimeException('x'))),
        fn () => $this->partialMock(FulfilmentHandoff::class, fn ($m) => $m->shouldReceive('openFor')->andThrow(new RuntimeException('x'))),
    ] as $break) {
        $break();

        try {
            app(FulfillOrderPayment::class)->handle($this->payment->fresh());
        } catch (Throwable) {
            // expected
        }

        $successful = OrderPayment::where('status', OrderPaymentStatus::Success)->count();
        $sales = InventoryTransaction::where('type', InventoryTransactionType::Sale)->count();

        expect($successful)->toBe($sales, 'a successful payment exists without a sale, or the reverse');
    }
});

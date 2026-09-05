<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Delivery\Exceptions\DeliveryNotAllowed;
use App\Domain\Delivery\Services\DeliveryLifecycle;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Delivery;
use App\Models\DeliveryTransition;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The state machine, and the order that follows it.
 *
 * Delivery is manual, which is exactly why every move is guarded: there is no
 * courier API to ask afterwards what really happened, so the record this
 * lifecycle writes is the only account there will ever be.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->staff = userWithRole('admin');
    $this->deliveries = app(DeliveryLifecycle::class);
});

// ------------------------------------------------------- Delivery creation

it('opens a delivery when a payment is verified', function (): void {
    $customer = bidder();
    $address = Address::factory()->ownedBy($customer)->isDefault()->create();

    $order = paidOrderFor($customer, $address);

    expect($order->delivery)->not->toBeNull()
        ->and($order->delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($order->delivery->reference)->toStartWith('AIC-D-')
        // Copied from the address book, not pointing at it.
        ->and($order->delivery->address_line)->toBe($address->address_line)
        ->and($order->delivery->source_address_id)->toBe($address->id);
});

it('opens no delivery for a checkout nobody paid for', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    initializePayment($order);

    // A payment was opened and never completed. Nothing to pack.
    expect(Delivery::count())->toBe(0);
});

it('opens no delivery for an expired checkout', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());

    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire($order->fresh());

    expect(Delivery::count())->toBe(0);
});

/*
 * A payment that succeeded against something that could not be delivered puts
 * nothing in front of the warehouse. There is no package to pack.
 */
it('opens no delivery for a blocked order', function (): void {
    $order = blockedPaidOrder();

    expect($order->isFulfilmentBlocked())->toBeTrue()
        ->and($order->fresh()->delivery)->toBeNull();
});

it('opens exactly one delivery however many times a webhook arrives', function (): void {
    $customer = bidder();
    Address::factory()->ownedBy($customer)->isDefault()->create();

    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($customer, $product->fresh());
    $payment = initializePayment($order);

    foreach (range(1, 5) as $ignored) {
        payOrder($order->fresh(), $payment->fresh());
    }

    expect(Delivery::where('order_id', $order->id)->count())->toBe(1);
});

it('refuses a second delivery row for one order', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    expect(fn () => DB::table('deliveries')->insert([
        'order_id' => $delivery->order_id,
        'user_id' => $delivery->user_id,
        'reference' => 'AIC-D-DUPLICATE',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
 * An auction winner's order is created by the closing sweep while nobody is at
 * a keyboard, so the delivery legitimately begins with nowhere to go.
 */
it('opens a delivery without an address when the customer has none', function (): void {
    $order = paidOrderFor(bidder());

    expect($order->delivery)->not->toBeNull()
        ->and($order->delivery->hasAddress())->toBeFalse()
        ->and($order->delivery->isAwaitingAddress())->toBeTrue();
});

it('will not let a package be prepared with nowhere to send it', function (): void {
    $order = paidOrderFor(bidder());

    expect(fn (): Delivery => $this->deliveries->prepare($order->delivery, $this->staff))
        ->toThrow(DeliveryNotAllowed::class, 'no address');

    expect($order->delivery->fresh()->status)->toBe(DeliveryStatus::Pending);
});

// ------------------------------------------------------- The happy path

it('walks a package all the way to the customer', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);
    $order = $delivery->order;

    $this->deliveries->prepare($delivery, $this->staff);
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        // The order moves with it: staff picking stock is what "processing"
        // commercially means.
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing);

    $this->deliveries->markReady($delivery->fresh(), $this->staff);
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::ReadyForDispatch)
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing);

    $this->deliveries->dispatch($delivery->fresh(), $this->staff, carrier: 'Kofi on the bike');
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Dispatched)
        ->and($delivery->fresh()->carrier)->toBe('Kofi on the bike')
        ->and($delivery->fresh()->attempts)->toBe(1)
        // Still processing. The box moving is not a commercial event.
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing);

    $this->deliveries->markOutForDelivery($delivery->fresh(), $this->staff);
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::OutForDelivery)
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing);

    $this->deliveries->markDelivered($delivery->fresh(), $this->staff, receivedBy: 'Ama');

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->fresh()->received_by)->toBe('Ama')
        ->and($delivery->fresh()->delivered_at)->not->toBeNull()
        // And only now does the order complete.
        ->and($order->fresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($order->fresh()->fulfilled_at)->not->toBeNull();
});

it('lets a package go straight from dispatched to delivered', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    $this->deliveries->markDelivered($delivery, $this->staff);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Delivered)
        // Out for delivery is optional, and never claimed when it did not
        // happen.
        ->and($delivery->fresh()->out_for_delivery_at)->toBeNull();
});

// ---------------------------------------------------- Invalid transitions

it('refuses to skip steps', function (string $from, string $to): void {
    $delivery = deliveryAt(DeliveryStatus::from($from));

    expect(fn (): Delivery => $this->deliveries->markDelivered($delivery, $this->staff))
        ->toThrow(DeliveryNotAllowed::class, 'cannot go from');

    expect($delivery->fresh()->status->value)->toBe($from);
})->with([
    ['pending', 'delivered'],
    ['preparing', 'delivered'],
    ['ready_for_dispatch', 'delivered'],
]);

it('refuses to move a delivered package anywhere', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Delivered);

    expect(DeliveryStatus::Delivered->isTerminal())->toBeTrue()
        ->and(fn (): Delivery => $this->deliveries->cancel($delivery, $this->staff))
        ->toThrow(DeliveryNotAllowed::class);
});

it('refuses to cancel a package that has already left', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    // It is out with a rider. It either arrives or it fails.
    expect(fn (): Delivery => $this->deliveries->cancel($delivery, $this->staff))
        ->toThrow(DeliveryNotAllowed::class);
});

it('refuses a status the database has never heard of', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    expect(fn () => DB::table('deliveries')->where('id', $delivery->id)
        ->update(['status' => 'teleported']))
        ->toThrow(QueryException::class);
});

// ------------------------------------------------------------- Failure

it('records a failed attempt without changing anything else', function (): void {
    $delivery = deliveryAt(DeliveryStatus::OutForDelivery);
    $order = $delivery->order;

    $this->deliveries->markFailed(
        $delivery,
        DeliveryFailureReason::RecipientUnavailable,
        $this->staff,
        'Nobody at the gate, called twice.',
    );

    $failed = $delivery->fresh();

    expect($failed->status)->toBe(DeliveryStatus::DeliveryFailed)
        ->and($failed->failure_reason)->toBe(DeliveryFailureReason::RecipientUnavailable)
        ->and($failed->failure_note)->toContain('gate')
        ->and($failed->failed_at)->not->toBeNull()
        // The order is untouched. The platform still has the money and still
        // has the item.
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing);
});

it('will not record a failure without a reason', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    expect(fn () => DB::table('deliveries')->where('id', $delivery->id)
        ->update(['status' => 'delivery_failed', 'failure_reason' => null]))
        ->toThrow(QueryException::class);
});

// --------------------------------------------------------------- Retry

it('lets staff try a failed delivery again', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    $this->deliveries->markFailed($delivery, DeliveryFailureReason::IncorrectAddress, $this->staff);

    $retried = $this->deliveries->retry($delivery->fresh(), DeliveryStatus::Preparing, $this->staff);

    expect($retried->status)->toBe(DeliveryStatus::Preparing)
        // The current state describes the present attempt; the failure stays
        // in the history where it belongs.
        ->and($retried->failure_reason)->toBeNull()
        ->and($retried->transitions()->where('reason_code', DeliveryFailureReason::IncorrectAddress)->exists())
        ->toBeTrue();
});

it('counts a second dispatch as a second attempt', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    $this->deliveries->markFailed($delivery, DeliveryFailureReason::PhoneUnreachable, $this->staff);
    $this->deliveries->retry($delivery->fresh(), DeliveryStatus::ReadyForDispatch, $this->staff);
    $this->deliveries->dispatch($delivery->fresh(), $this->staff);

    expect($delivery->fresh()->attempts)->toBe(2);
});

it('refuses to retry to a state that does not mean the package is in hand', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);
    $this->deliveries->markFailed($delivery, DeliveryFailureReason::Other, $this->staff);

    expect(fn (): Delivery => $this->deliveries->retry($delivery->fresh(), DeliveryStatus::Delivered, $this->staff))
        ->toThrow(DeliveryNotAllowed::class, 'back in our hands');
});

it('retries nothing on its own', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);
    $this->deliveries->markFailed($delivery, DeliveryFailureReason::RecipientRefused, $this->staff);

    // Time passes and the sweeps run. A failed delivery stays failed until
    // somebody decides otherwise.
    $this->travel(7)->days();
    $this->artisan('orders:expire-checkouts')->assertSuccessful();
    $this->artisan('auctions:tick')->assertSuccessful();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::DeliveryFailed);
});

// ------------------------------------------------------- Cancellation

it('cancels a package that has not gone out', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Preparing);
    $order = $delivery->order;

    $this->deliveries->cancel($delivery, $this->staff, 'Customer asked us to hold it.');

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Cancelled)
        ->and($delivery->fresh()->cancelled_at)->not->toBeNull()
        // The order is not cancelled by this. Money and packages are separate
        // questions, and what the customer is owed is a separate decision.
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing);
});

// ---------------------------------------------------------- Idempotency

it('does nothing at all when asked to move to where it already is', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Preparing);

    $before = DeliveryTransition::where('delivery_id', $delivery->id)->count();

    $this->deliveries->prepare($delivery->fresh(), $this->staff);
    $this->deliveries->prepare($delivery->fresh(), $this->staff);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        // No second history row: nothing happened, so nothing is recorded.
        ->and(DeliveryTransition::where('delivery_id', $delivery->id)->count())->toBe($before);
});

it('completes an order once however many times delivered is pressed', function (): void {
    $delivery = deliveryAt(DeliveryStatus::OutForDelivery);
    $order = $delivery->order;

    foreach (range(1, 4) as $ignored) {
        $this->deliveries->markDelivered($delivery->fresh(), $this->staff);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($order->fresh()->transitions()->where('to_status', OrderStatus::Fulfilled)->count())->toBe(1)
        ->and($delivery->fresh()->transitions()->where('to_status', DeliveryStatus::Delivered)->count())->toBe(1);
});

// --------------------------------------------------------------- Audit

it('records every move with an actor and a time', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Delivered, $this->staff);

    $history = $delivery->transitions()->orderBy('id')->get();

    expect($history->pluck('to_status')->map(fn ($s) => $s->value)->all())->toBe([
        'pending', 'preparing', 'ready_for_dispatch', 'dispatched', 'out_for_delivery', 'delivered',
    ]);

    // The opening row is the system's; every move after it names who did it.
    expect($history->first()->caused_by)->toBeNull()
        ->and($history->skip(1)->pluck('caused_by')->unique()->all())->toBe([$this->staff->id])
        ->and($history->every(fn ($t): bool => $t->created_at !== null))->toBeTrue();
});

it('keeps delivery history append-only', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Preparing);
    $transition = $delivery->transitions()->first();

    expect(fn () => DB::table('delivery_transitions')->where('id', $transition->id)
        ->update(['to_status' => 'delivered']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('delivery_transitions')->where('id', $transition->id)->delete())
        ->toThrow(QueryException::class);
});

it('records the failure reason on the transition, not just the delivery', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    $this->deliveries->markFailed($delivery, DeliveryFailureReason::DamagedPackage, $this->staff, 'Box crushed.');

    $transition = $delivery->fresh()->transitions()->latest('id')->first();

    expect($transition->reason_code)->toBe(DeliveryFailureReason::DamagedPackage)
        ->and($transition->note)->toBe('Box crushed.')
        ->and($transition->caused_by)->toBe($this->staff->id);
});

<?php

declare(strict_types=1);

use App\Domain\Delivery\Services\DeliveryLifecycle;
use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Livewire\Admin\Delivery\FulfilmentQueue;
use App\Livewire\Admin\Orders\OrderDetail as AdminOrderDetail;
use App\Livewire\Delivery\OrderTracking;
use App\Livewire\Orders\OrderDetail;
use App\Models\Address;
use App\Models\User;
use Livewire\Livewire;

/*
 * Who may move a package, what staff see, and what the customer is told.
 *
 * Authorization is checked on the server every time. A button that is not
 * rendered is not a rule -- these tests call the methods directly, which is
 * what somebody circumventing the interface would do.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->staff = userWithRole('admin');
    $this->deliveries = app(DeliveryLifecycle::class);
});

/**
 * A member of staff holding exactly the named permissions and nothing else.
 *
 * Deliberately given no role: `syncPermissions` replaces only direct
 * permissions, so an admin stripped that way still holds everything through
 * the role and a test built on one would assert nothing.
 */
function warehouseStaff(array $permissions): User
{
    seedPermissions();

    $user = User::factory()->create();
    $user->syncPermissions($permissions);

    return $user->fresh();
}

// ------------------------------------------------------------- The queue

it('shows staff what needs packing', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    Livewire::actingAs($this->staff)
        ->test(FulfilmentQueue::class)
        ->assertOk()
        ->assertSee($delivery->reference)
        ->assertSee($delivery->order->order_number);
});

it('separates packages waiting on the customer from work staff can do', function (): void {
    // No address: waiting on the customer, not on the warehouse.
    $waiting = paidOrderFor(bidder())->delivery;

    Livewire::actingAs($this->staff)
        ->test(FulfilmentQueue::class)
        ->set('filter', 'awaiting_address')
        ->assertSee($waiting->reference)
        ->assertSee('waiting on the customer');
});

it('filters by delivery state', function (): void {
    $dispatched = deliveryAt(DeliveryStatus::Dispatched);

    Livewire::actingAs($this->staff)
        ->test(FulfilmentQueue::class)
        ->set('filter', 'dispatched')
        ->assertSee($dispatched->reference)
        ->set('filter', 'delivered')
        ->assertDontSee($dispatched->reference);
});

it('finds a package by its reference', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    Livewire::actingAs($this->staff)
        ->test(FulfilmentQueue::class)
        ->set('filter', 'outstanding')
        ->set('search', $delivery->reference)
        ->assertSee($delivery->reference);
});

it('offers no way to move a package from the queue', function (): void {
    // Every move happens on the order's own screen, where whoever is doing it
    // can see the address and the customer first.
    // Not `dispatch`: Livewire's own Component defines one for events, so
    // asserting its absence would be testing the framework rather than this
    // screen.
    expect(method_exists(FulfilmentQueue::class, 'moveDelivery'))->toBeFalse()
        ->and(method_exists(FulfilmentQueue::class, 'markDelivered'))->toBeFalse()
        ->and(method_exists(FulfilmentQueue::class, 'markReady'))->toBeFalse()
        ->and(method_exists(FulfilmentQueue::class, 'failDelivery'))->toBeFalse()
        ->and(method_exists(FulfilmentQueue::class, 'cancelDelivery'))->toBeFalse();
});

// --------------------------------------------------------- Authorization

it('keeps a customer out of the fulfilment queue', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('admin.fulfilment'))
        ->assertForbidden();
});

it('forbids a customer from the queue component', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(FulfilmentQueue::class)
        ->assertForbidden();
});

it('refuses to pack without the update permission', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);
    $limited = warehouseStaff(['orders.view', 'deliveries.view']);

    Livewire::actingAs($limited)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->call('moveDelivery', 'preparing')
        ->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Pending);
});

/*
 * The split that matters: somebody who packs boxes is not thereby entitled to
 * declare an order complete.
 */
it('refuses to complete an order without the complete permission', function (): void {
    $delivery = deliveryAt(DeliveryStatus::OutForDelivery);
    $packer = warehouseStaff(['orders.view', 'deliveries.view', 'deliveries.update']);

    Livewire::actingAs($packer)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->call('moveDelivery', 'delivered')
        ->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::OutForDelivery)
        ->and($delivery->order->fresh()->status)->not->toBe(OrderStatus::Fulfilled);
});

it('refuses to dispatch without the dispatch permission', function (): void {
    $delivery = deliveryAt(DeliveryStatus::ReadyForDispatch);
    $packer = warehouseStaff(['orders.view', 'deliveries.view', 'deliveries.update']);

    Livewire::actingAs($packer)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->call('moveDelivery', 'dispatched')
        ->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::ReadyForDispatch);
});

it('refuses to cancel without the cancel permission', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Preparing);
    $packer = warehouseStaff(['orders.view', 'deliveries.view', 'deliveries.update']);

    Livewire::actingAs($packer)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->call('moveDelivery', 'cancelled')
        ->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Preparing);
});

it('refuses to retry without the retry permission', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);
    $this->deliveries->markFailed($delivery, DeliveryFailureReason::Other, $this->staff);

    $packer = warehouseStaff(['orders.view', 'deliveries.view', 'deliveries.update']);

    Livewire::actingAs($packer)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->call('retryDelivery', 'preparing')
        ->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::DeliveryFailed);
});

/*
 * A customer cannot advance their own delivery, however much they might like
 * to. The component is staff-only from `mount` onwards.
 */
it('gives a customer no way to move their own package', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    Livewire::actingAs($delivery->order->user)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->assertForbidden();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Dispatched);
});

// --------------------------------------------------------- Staff screens

it('lets staff walk a package through on the order screen', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    Livewire::actingAs($this->staff)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->call('moveDelivery', 'preparing')
        ->call('moveDelivery', 'ready_for_dispatch')
        ->set('carrier', 'Kofi on the bike')
        ->set('trackingReference', 'RIDER-9')
        ->call('moveDelivery', 'dispatched')
        ->set('receivedBy', 'Ama')
        ->call('moveDelivery', 'delivered')
        ->assertHasNoErrors();

    $done = $delivery->fresh();

    expect($done->status)->toBe(DeliveryStatus::Delivered)
        ->and($done->carrier)->toBe('Kofi on the bike')
        ->and($done->tracking_reference)->toBe('RIDER-9')
        ->and($done->received_by)->toBe('Ama')
        ->and($delivery->order->fresh()->status)->toBe(OrderStatus::Fulfilled);
});

it('records a failed attempt from the order screen', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    Livewire::actingAs($this->staff)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->set('failureReason', DeliveryFailureReason::RecipientUnavailable->value)
        ->set('deliveryNote', 'Nobody at the gate.')
        ->call('failDelivery')
        ->assertHasNoErrors();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::DeliveryFailed)
        ->and($delivery->fresh()->failure_reason)->toBe(DeliveryFailureReason::RecipientUnavailable);
});

it('will not record a failure without a reason', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    Livewire::actingAs($this->staff)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->call('failDelivery')
        ->assertHasErrors('delivery');

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Dispatched);
});

it('shows staff the delivery history', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched, $this->staff);

    Livewire::actingAs($this->staff)
        ->test(AdminOrderDetail::class, ['order' => $delivery->order])
        ->assertSee('Delivery history')
        ->assertSee('Ready for dispatch')
        ->assertSee($this->staff->name);
});

// ------------------------------------------------------ What customers see

it('shows a customer only the steps that happened', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    Livewire::actingAs($delivery->order->user)
        ->test(OrderTracking::class, ['order' => $delivery->order])
        ->assertOk()
        ->assertSee('On its way')
        ->assertSee('Preparing your order')
        // Delivered is a future state and is never rendered as done.
        ->assertSee('Delivered')
        ->assertSee($delivery->reference);

    expect($delivery->delivered_at)->toBeNull();
});

it('shows a customer nothing internal', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    $delivery->staff_notes = 'Customer is a nuisance, avoid the dog.';
    $delivery->tracking_reference = 'INTERNAL-REF-9';
    $delivery->save();

    $this->deliveries->markFailed(
        $delivery->fresh(),
        DeliveryFailureReason::RecipientRefused,
        $this->staff,
        'Slammed the door.',
    );

    Livewire::actingAs($delivery->order->user)
        ->test(OrderTracking::class, ['order' => $delivery->fresh()->order])
        ->assertDontSee('nuisance')
        ->assertDontSee('Slammed the door')
        ->assertDontSee('INTERNAL-REF-9')
        // The raw code never reaches them; the neutral description does.
        ->assertDontSee('recipient_refused')
        ->assertSee('not accepted');
});

it('does not show one customer another customer tracking', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    $this->actingAs(userWithRole('customer'))
        ->get(route('orders.tracking', $delivery->order))
        ->assertNotFound();
});

it('lets a customer supply an address for a package waiting on one', function (): void {
    $customer = bidder();
    $order = paidOrderFor($customer);
    $address = Address::factory()->ownedBy($customer)->create();

    expect($order->delivery->isAwaitingAddress())->toBeTrue();

    Livewire::actingAs($customer)
        ->test(OrderTracking::class, ['order' => $order])
        ->set('selectedAddressId', $address->id)
        ->call('chooseAddress')
        ->assertHasNoErrors();

    $delivery = $order->fresh()->delivery;

    expect($delivery->hasAddress())->toBeTrue()
        ->and($delivery->address_line)->toBe($address->address_line);
});

it('refuses an address the customer does not own', function (): void {
    $customer = bidder();
    $order = paidOrderFor($customer);
    $someoneElses = Address::factory()->ownedBy(bidder())->create();

    Livewire::actingAs($customer)
        ->test(OrderTracking::class, ['order' => $order])
        ->set('selectedAddressId', $someoneElses->id)
        ->call('chooseAddress')
        ->assertHasErrors('address');

    expect($order->fresh()->delivery->hasAddress())->toBeFalse();
});

it('refuses to change an address once the package is being handled', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Preparing);
    $customer = $delivery->order->user;
    $other = Address::factory()->ownedBy($customer)->create();

    Livewire::actingAs($customer)
        ->test(OrderTracking::class, ['order' => $delivery->order])
        ->set('selectedAddressId', $other->id)
        ->call('chooseAddress')
        ->assertHasErrors('address');

    expect($delivery->fresh()->address_line)->not->toBe($other->address_line);
});

it('shows a customer their delivery on the order page', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    Livewire::actingAs($delivery->order->user)
        ->test(OrderDetail::class, ['order' => $delivery->order])
        ->assertSee('On its way')
        ->assertSee('Track this order');
});

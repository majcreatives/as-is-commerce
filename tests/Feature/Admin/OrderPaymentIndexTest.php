<?php

declare(strict_types=1);

use App\Enums\OrderPaymentStatus;
use App\Livewire\Admin\Payments\OrderPaymentIndex;
use App\Models\User;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;

/*
 * The payment attempts screen.
 *
 * Its whole value is that it reads. The tests that matter most here are the
 * ones proving what it cannot do: there is no way, from this screen or from
 * anywhere else, to assert that money arrived.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

// --------------------------------------------------------------- Access

it('is reachable by an administrator', function (): void {
    $this->actingAs($this->admin)->get(route('admin.payments'))->assertOk();
});

it('is refused to a customer', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.payments'))
        ->assertForbidden();
});

it('is refused to staff without the permission', function (): void {
    Livewire::actingAs(staffWith(['orders.view']))
        ->test(OrderPaymentIndex::class)
        ->assertForbidden();
});

// --------------------------------------------------------------- Listing

it('lists an attempt with the amount it was actually opened with', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    Livewire::actingAs($this->admin)
        ->test(OrderPaymentIndex::class)
        ->assertOk()
        ->assertSee($payment->provider_reference)
        ->assertSee($order->order_number)
        // What verification compares against is the attempt's own frozen
        // amount, so that is what this screen shows.
        ->assertSee($payment->amount()->format());
});

it('filters by what the provider answered', function (): void {
    $paidOrder = buyNowCheckout(bidder(), stockedProduct());
    $settled = initializePayment($paidOrder);
    payOrder($paidOrder, $settled);

    $open = initializePayment(buyNowCheckout(bidder(), stockedProduct()));

    Livewire::actingAs($this->admin)
        ->test(OrderPaymentIndex::class)
        ->set('status', OrderPaymentStatus::Success->value)
        ->assertOk()
        ->assertSee($settled->fresh()->provider_reference)
        ->assertDontSee($open->provider_reference);
});

it('renders an honest empty state', function (): void {
    Livewire::actingAs($this->admin)
        ->test(OrderPaymentIndex::class)
        ->assertOk()
        ->assertSee('No payment attempt');
});

// --------------------------------------------------------------- Safety

it('has no control that marks anything paid', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    initializePayment($order);

    $component = Livewire::actingAs($this->admin)
        ->test(OrderPaymentIndex::class)
        ->assertOk();

    foreach (['Mark paid', 'Mark as paid', 'Confirm payment', 'Force success'] as $forbidden) {
        $component->assertDontSee($forbidden);
    }

    // Everything the component adds beyond Livewire's own base and its
    // pagination trait. mount, render, and the updated() hook that resets the
    // page when a filter changes -- and nothing that acts.
    $own = array_diff(
        get_class_methods(OrderPaymentIndex::class),
        get_class_methods(Component::class),
        get_class_methods(new class extends Component
        {
            use WithPagination;
        }),
    );

    expect(array_values($own))->toEqualCanonicalizing(['mount', 'updated', 'render']);
});

it('shows no provider credential', function (): void {
    config(['paystack.secret_key' => 'sk_test_never_rendered']);

    $order = buyNowCheckout(bidder(), stockedProduct());
    initializePayment($order);

    $html = Livewire::actingAs($this->admin)->test(OrderPaymentIndex::class)->html();

    // The provider reference identifies a transaction and is not a secret.
    // The secret key, and anything resembling card data, must never appear.
    expect($html)->not->toContain('sk_test_never_rendered')
        ->and($html)->not->toContain('authorization_code')
        ->and($html)->not->toContain('card_type');
});

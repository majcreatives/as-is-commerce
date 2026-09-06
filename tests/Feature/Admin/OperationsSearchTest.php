<?php

declare(strict_types=1);

use App\Domain\Operations\Queries\OperationsSearch;
use App\Livewire\Admin\Operations\GlobalSearch;
use App\Models\User;
use Livewire\Livewire;

/*
 * Admin search.
 *
 * Support has a string and should not have to know which of eight screens it
 * belongs to. These tests hold the box to three things: it finds the record
 * from the reference somebody is actually holding, it is bounded in every
 * direction, and it never becomes a way round authorization.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

// --------------------------------------------------------------- Access

it('is reachable by an administrator', function (): void {
    $this->actingAs($this->admin)->get(route('admin.search'))->assertOk();
});

it('is refused to a customer', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.search'))
        ->assertForbidden();
});

// --------------------------------------------------------------- Finding

it('finds an order by its number', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    $results = app(OperationsSearch::class)->search($order->order_number);

    expect($results)->toHaveKey('orders')
        ->and($results['orders']->pluck('id')->all())->toContain($order->id);
});

it('finds a customer by a phone number typed the way they say it', function (): void {
    $customer = User::factory()->create(['phone' => '+233241234567']);

    // Stored E.164, typed locally. Without normalization this returns nothing,
    // which is exactly the search support needs most.
    $results = app(OperationsSearch::class)->search('0241234567');

    expect($results)->toHaveKey('customers')
        ->and($results['customers']->pluck('id')->all())->toContain($customer->id);
});

it('finds a payment attempt by the provider reference', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    $results = app(OperationsSearch::class)->search($payment->provider_reference);

    expect($results)->toHaveKey('payments')
        ->and($results['payments']->pluck('id')->all())->toContain($payment->id);
});

it('finds a product by its SKU', function (): void {
    $product = stockedProduct();

    $results = app(OperationsSearch::class)->search($product->sku);

    expect($results)->toHaveKey('products')
        ->and($results['products']->pluck('id')->all())->toContain($product->id);
});

it('returns no empty groups', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    foreach (app(OperationsSearch::class)->search($order->order_number) as $group) {
        expect($group->isNotEmpty())->toBeTrue();
    }
});

// --------------------------------------------------------------- Bounds

it('refuses a term too short to be a lookup', function (): void {
    expect(app(OperationsSearch::class)->isTooShort('a'))->toBeTrue()
        ->and(app(OperationsSearch::class)->search('a'))->toBe([]);
});

it('caps the length of anything that reaches a query', function (): void {
    $long = str_repeat('x', 5_000);

    expect(mb_strlen(app(OperationsSearch::class)->clean($long)))
        ->toBe(OperationsSearch::MAX_LENGTH);
});

it('limits how much of any one kind it will return', function (): void {
    $shared = 'SHAREDNAME';

    User::factory()->count(OperationsSearch::PER_TYPE + 5)->create([
        'name' => $shared,
    ]);

    expect(app(OperationsSearch::class)->search($shared)['customers'])
        ->toHaveCount(OperationsSearch::PER_TYPE);
});

// --------------------------------------------------------------- Authorization

it('shows a group only to staff who may already see that kind of record', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    // Somebody who may search but may not see orders.
    Livewire::actingAs(staffWith(['admin.dashboard.view', 'customers.view']))
        ->test(GlobalSearch::class, ['q' => $order->order_number])
        ->assertOk()
        ->assertDontSee($order->order_number);

    Livewire::actingAs($this->admin)
        ->test(GlobalSearch::class, ['q' => $order->order_number])
        ->assertOk()
        ->assertSee($order->order_number);
});

it('is refused to staff without the permission', function (): void {
    Livewire::actingAs(staffWith(['orders.view']))
        ->test(GlobalSearch::class)
        ->assertForbidden();
});

it('renders an honest empty state rather than guessing', function (): void {
    Livewire::actingAs($this->admin)
        ->test(GlobalSearch::class, ['q' => 'ORD-NOTHING-MATCHES'])
        ->assertOk()
        ->assertSee('Nothing matched');
});

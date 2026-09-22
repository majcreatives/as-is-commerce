<?php

declare(strict_types=1);

use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Livewire\Admin\Customers\CustomerDetail;
use App\Livewire\Admin\Customers\CustomerIndex;
use App\Models\User;
use Livewire\Component;
use Livewire\Livewire;

/*
 * The customer support view.
 *
 * Somebody on a call needs what this person bought, what they owe, where their
 * packages are and whether anything of theirs is stuck. These tests hold the
 * screen to two things: it assembles that from the tables that own each fact,
 * and it changes nothing.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

// --------------------------------------------------------------- Access

it('is reachable by an administrator', function (): void {
    $customer = User::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.customers.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.customers.show', $customer))->assertOk();
});

it('is refused to a customer', function (): void {
    $customer = User::factory()->create();

    $this->actingAs($customer)->get(route('admin.customers.index'))->assertForbidden();
    $this->actingAs($customer)->get(route('admin.customers.show', $customer))->assertForbidden();
});

it('is refused to staff without the permission', function (): void {
    Livewire::actingAs(staffWith(['orders.view']))
        ->test(CustomerIndex::class)
        ->assertForbidden();

    Livewire::actingAs(staffWith(['orders.view']))
        ->test(CustomerDetail::class, ['user' => User::factory()->create()])
        ->assertForbidden();
});

// --------------------------------------------------------------- Finding

it('finds a customer by a phone number typed the way they say it', function (): void {
    $customer = User::factory()->create(['phone' => '+233241234567', 'name' => 'Ama Mensah']);

    Livewire::actingAs($this->admin)
        ->test(CustomerIndex::class)
        ->set('search', '0241234567')
        ->assertOk()
        ->assertSee('Ama Mensah');
});

it('renders an honest empty state when nobody matches', function (): void {
    Livewire::actingAs($this->admin)
        ->test(CustomerIndex::class)
        ->set('search', 'nobody-by-that-name')
        ->assertOk()
        ->assertSee('No customer found');
});

// --------------------------------------------------------------- What it shows

it('shows what the customer bought and what happened to it', function (): void {
    $customer = bidder();
    $order = buyNowCheckout($customer, stockedProduct());
    payOrder($order);

    Livewire::actingAs($this->admin)
        ->test(CustomerDetail::class, ['user' => $customer])
        ->assertOk()
        ->assertSee($order->order_number);
});

it('keeps spendable credits and credits consumed on bids as separate figures', function (): void {
    // Multiplied by the redenomination factor so the DISPLAYED figures below
    // -- "850 credits", "150 credits" -- are what actually renders. Bid and
    // wallet arithmetic is scale-invariant; only what a customer reads depends
    // on this factor, via CreditAmount.
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $customer = bidder(1_000 * $factor);
    $auction = liveAuction();
    placeBid($auction, $customer, 150 * $factor);

    $rendered = Livewire::actingAs($this->admin)
        ->test(CustomerDetail::class, ['user' => $customer])
        ->assertOk()
        ->assertSee('Credit balance')
        ->assertSee('Committed to bids');

    // Two facts, never one. Credits are a count and never money, so no
    // currency symbol may appear beside either.
    $rendered->assertSee('850 credits')
        ->assertSee('150 credits')
        ->assertDontSee('GH₵850')
        ->assertDontSee('GH₵150');
});

it('shows a blocked payment without offering a refund of its own', function (): void {
    $order = blockedPaidOrder();

    Livewire::actingAs($this->admin)
        ->test(CustomerDetail::class, ['user' => $order->user])
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('Blocked')
        // Refunding lives on the order, behind refunds.request. A second
        // control here would be a second implementation of the rule.
        ->assertDontSee('Refund this order');
});

// --------------------------------------------------------------- Safety

it('exposes no method that changes anything', function (): void {
    $own = array_diff(
        get_class_methods(CustomerDetail::class),
        get_class_methods(Component::class),
    );

    // mount and render, and nothing else. Every action lives on the screen
    // that owns the record, behind the permission that governs it.
    expect(array_values($own))->toEqualCanonicalizing(['mount', 'render']);
});

it('never renders authentication material', function (): void {
    $customer = User::factory()->create();

    $html = Livewire::actingAs($this->admin)
        ->test(CustomerDetail::class, ['user' => $customer])
        ->html();

    expect($html)->not->toContain($customer->getAuthPassword())
        ->and($html)->not->toContain((string) $customer->remember_token)
        ->and($html)->not->toContain('password');
});

it('offers no mark-paid, no balance field and no status control', function (): void {
    $order = blockedPaidOrder();

    $component = Livewire::actingAs($this->admin)
        ->test(CustomerDetail::class, ['user' => $order->user]);

    foreach (['Mark paid', 'Mark as paid', 'Set balance', 'Adjust credits', 'Change status'] as $forbidden) {
        $component->assertDontSee($forbidden);
    }
});

<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Operations\Queries\OperationsMetrics;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Enums\OrderStatus;
use App\Livewire\Admin\Operations\OperationsDashboard;
use App\Models\User;
use Livewire\Livewire;

/*
 * The operations dashboard.
 *
 * It counts, and it links. The point of these tests is that the counting is
 * done against the tables that own each fact -- so a figure moves only when the
 * underlying records move -- and that nothing on the page changes anything.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();
});

// --------------------------------------------------------------- Access

it('is reachable by an administrator', function (): void {
    $this->actingAs(userWithRole('admin'))
        ->get(route('admin.dashboard'))
        ->assertOk();
});

it('is refused to a customer', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

it('is refused to a guest', function (): void {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

// --------------------------------------------------------------- Figures

it('renders an honest empty platform rather than placeholder numbers', function (): void {
    Livewire::actingAs(userWithRole('admin'))
        ->test(OperationsDashboard::class)
        ->assertOk()
        ->assertSee('Live auctions')
        ->assertSee('Nothing needs attention right now.');
});

it('counts a live auction from the auction records', function (): void {
    expect(app(OperationsMetrics::class)->commerce()['auctions_live'])->toBe(0);

    liveAuction();

    expect(app(OperationsMetrics::class)->commerce()['auctions_live'])->toBe(1);
});

it('counts collected money only from orders with a verified payment', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    // An open checkout is an obligation, not money. It must not be counted.
    expect(app(OperationsMetrics::class)->collected()->minor)->toBe(0);

    payOrder($order);

    expect(app(OperationsMetrics::class)->collected()->minor)
        ->toBe($order->fresh()->total_minor);
});

it('reports collected and refunded as separate figures and never nets them', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    $collected = app(OperationsMetrics::class)->collected()->minor;

    // The refund has been asked for but the provider has not confirmed it, so
    // nothing has been returned.
    expect(app(OperationsMetrics::class)->refunded()->minor)->toBe(0)
        ->and($collected)->toBeGreaterThan(0);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-METRICS'));
    app(ProcessRefund::class)->handle($refund->fresh(), userWithRole('admin'));

    $metrics = app(OperationsMetrics::class);

    // Money taken and money given back are two facts. Refunding must not move
    // the collected figure, because netting them would describe neither.
    expect($metrics->refunded()->minor)->toBe($refund->amount_minor)
        ->and($metrics->collected()->minor)->toBe($collected);
});

it('counts stock from the product projections the inventory ledger maintains', function (): void {
    $product = stockedProduct(stock: 5);

    $inventory = app(OperationsMetrics::class)->inventory();

    expect($inventory['units_on_hand'])->toBe(5)
        ->and($inventory['units_reserved'])->toBe(0);

    app(InventoryService::class)->reserve($product->fresh(), 2, reason: 'Stage 14 test');

    expect(app(OperationsMetrics::class)->inventory()['units_reserved'])->toBe(2);
});

it('counts an order awaiting payment separately from a paid one', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    expect(app(OperationsMetrics::class)->commerce()['orders_awaiting_payment'])->toBe(1);

    payOrder($order);

    $commerce = app(OperationsMetrics::class)->commerce();

    expect($commerce['orders_awaiting_payment'])->toBe(0)
        ->and($order->fresh()->status)->not->toBe(OrderStatus::PendingPayment);
});

// --------------------------------------------------------------- Safety

it('offers no control that changes anything', function (): void {
    $component = Livewire::actingAs(userWithRole('admin'))->test(OperationsDashboard::class);

    // Every public method on the component is Livewire's own. There is no
    // action here, because a dashboard that could act would be a second place
    // the domain rules live.
    $own = array_filter(
        get_class_methods(OperationsDashboard::class),
        fn (string $method): bool => in_array($method, ['mount', 'render'], true),
    );

    expect(array_values($own))->toEqualCanonicalizing(['mount', 'render']);

    $component->assertDontSee('Mark paid')
        ->assertDontSee('Adjust balance');
});

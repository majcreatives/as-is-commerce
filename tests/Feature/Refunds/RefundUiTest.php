<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Enums\RefundStatus;
use App\Livewire\Admin\Orders\OrderDetail as AdminOrderDetail;
use App\Livewire\Admin\Refunds\RefundQueue;
use App\Livewire\Orders\OrderDetail as CustomerOrderDetail;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Who may refund, what they see, and what the customer is told.
 *
 * Authorization is checked on the server every time. A button that is not
 * rendered is not a rule -- these tests call the methods directly, which is
 * what somebody circumventing the interface would do.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->admin = userWithRole('admin');
});

/**
 * A member of staff holding exactly the named permissions and nothing else.
 *
 * Deliberately given no role. `syncPermissions` replaces only a user's direct
 * permissions, so an admin stripped this way still holds everything through
 * the role -- and a test built that way would assert nothing. This is the
 * narrower finance operator the split permissions exist for.
 */
function refundStaff(array $permissions): User
{
    seedPermissions();

    $user = User::factory()->create();
    $user->syncPermissions($permissions);

    return $user->fresh();
}

// ------------------------------------------------------------ The queue

it('shows staff the orders waiting for a decision', function (): void {
    $order = blockedPaidOrder();

    Livewire::actingAs($this->admin)
        ->test(RefundQueue::class)
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('waiting for somebody to decide');
});

it('drops an order out of the queue once a refund is started', function (): void {
    $order = blockedPaidOrder();

    requestRefund($order, actor: $this->admin);

    Livewire::actingAs($this->admin)
        ->test(RefundQueue::class)
        ->assertDontSee($order->order_number);
});

it('shows an empty state when nothing is owed', function (): void {
    Livewire::actingAs($this->admin)
        ->test(RefundQueue::class)
        ->assertSee('Nothing awaiting recovery');
});

it('lists refunds by status', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-UI1'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(RefundQueue::class)
        ->set('filter', 'succeeded')
        ->assertSee($order->order_number)
        ->set('filter', 'failed')
        ->assertDontSee($order->order_number);
});

it('offers staff no way to mark a refund succeeded or delete one', function (): void {
    expect(method_exists(RefundQueue::class, 'markSucceeded'))->toBeFalse()
        ->and(method_exists(RefundQueue::class, 'delete'))->toBeFalse()
        ->and(method_exists(RefundQueue::class, 'setStatus'))->toBeFalse()
        ->and(method_exists(RefundQueue::class, 'editAmount'))->toBeFalse();
});

// --------------------------------------------------------- Authorization

it('keeps a customer out of the refund queue', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('admin.refunds'))
        ->assertForbidden();
});

it('forbids a customer from the refund component', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(RefundQueue::class)
        ->assertForbidden();
});

it('grants a customer none of the refund permissions', function (): void {
    $customer = userWithRole('customer');

    foreach (['refunds.view', 'refunds.request', 'refunds.process', 'refunds.retry', 'refunds.inspect'] as $permission) {
        expect($customer->can($permission))->toBeFalse("A customer must not hold {$permission}.");
    }
});

it('refuses to start a refund without the request permission', function (): void {
    $order = blockedPaidOrder();
    $staff = refundStaff(['orders.view']);

    Livewire::actingAs($staff)
        ->test(AdminOrderDetail::class, ['order' => $order])
        ->call('startRefund')
        ->assertForbidden();

    expect(Refund::count())->toBe(0);
});

it('refuses to send a refund without the process permission', function (): void {
    $order = blockedPaidOrder();
    $staff = refundStaff(['orders.view', 'refunds.view', 'refunds.request']);

    Livewire::actingAs($staff)
        ->test(AdminOrderDetail::class, ['order' => $order])
        ->call('startRefund')
        ->call('confirmRefund')
        ->assertForbidden();

    // Recording an intention is one capability; sending money is another, and
    // failing the second must not leave the first half-done.
    expect(Refund::count())->toBe(0);
});

it('refuses to retry without the retry permission', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    $staff = refundStaff(['refunds.view']);

    Livewire::actingAs($staff)
        ->test(RefundQueue::class)
        ->call('retry', $refund->id)
        ->assertForbidden();

    expect($refund->fresh()->status)->toBe(RefundStatus::Pending);
});

// ------------------------------------------------- The confirmation step

it('does not refund on the first click', function (): void {
    $order = blockedPaidOrder();

    Livewire::actingAs($this->admin)
        ->test(AdminOrderDetail::class, ['order' => $order])
        ->call('startRefund')
        ->assertSet('confirmingRefund', true)
        ->assertSee('Confirm this refund')
        ->assertSee('Returns no Credits')
        ->assertSee('Puts no stock back');

    expect(Refund::count())->toBe(0);
});

it('refunds once the confirmation is confirmed', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    fakePaystackRefund(paystackRefundBody($payment->amount_minor, 'processed', 'RF-UI2'));

    Livewire::actingAs($this->admin)
        ->test(AdminOrderDetail::class, ['order' => $order])
        ->call('startRefund')
        ->call('confirmRefund')
        ->assertSet('confirmingRefund', false);

    expect(Refund::count())->toBe(1)
        ->and(Refund::first()->status)->toBe(RefundStatus::Succeeded);
});

it('shows an operator why a refund was refused', function (): void {
    $order = blockedPaidOrder();

    requestRefund($order, actor: $this->admin);

    Livewire::actingAs($this->admin)
        ->test(AdminOrderDetail::class, ['order' => $order->fresh()])
        ->call('startRefund')
        ->call('confirmRefund')
        ->assertHasErrors('refund');
});

it('offers no refund control on an order that is not a recovery case', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    payOrder($order);

    Livewire::actingAs($this->admin)
        ->test(AdminOrderDetail::class, ['order' => $order->fresh()])
        ->assertSet('confirmingRefund', false)
        ->assertDontSee('Refund this payment');
});

// ------------------------------------------------------- What staff see

it('shows the refundable amount and what has gone back', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-UI3'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    Livewire::actingAs($this->admin)
        ->test(AdminOrderDetail::class, ['order' => $order->fresh()])
        ->assertSee('Returned so far')
        ->assertSee('Still refundable')
        ->assertSee('Refunded');
});

// ------------------------------------------------------ What customers see

it('tells a customer a refund is on its way, not that it is done', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-UI4'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    Livewire::actingAs($order->user)
        ->test(CustomerOrderDetail::class, ['order' => $order->fresh()])
        ->assertSee('asked our payment provider to return this')
        ->assertDontSee('We returned this to the payment method');
});

it('tells a customer when the money has actually gone back', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-UI5'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    Livewire::actingAs($order->user)
        ->test(CustomerOrderDetail::class, ['order' => $order->fresh()])
        ->assertSee('We returned this to the payment method')
        ->assertSee('Credits you spent bidding remain consumed');
});

it('shows a customer nothing about a refund that has only been recorded', function (): void {
    $order = blockedPaidOrder();

    requestRefund($order, actor: $this->admin);

    // The provider has not been asked. Showing this would tell a customer
    // something is happening when nothing yet has.
    Livewire::actingAs($order->user)
        ->test(CustomerOrderDetail::class, ['order' => $order->fresh()])
        ->assertDontSee('asked our payment provider');
});

it('shows a customer no internal detail about a failed refund', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    fakeHttp([
        'api.paystack.co/refund' => Http::response(
            ['status' => false, 'message' => 'Internal provider fault XYZ-9'],
            400,
        ),
    ]);

    app(ProcessRefund::class)->handle($refund, $this->admin);

    Livewire::actingAs($order->user)
        ->test(CustomerOrderDetail::class, ['order' => $order->fresh()])
        ->assertSee('did not go through')
        // The provider's own words are for staff, not for the customer.
        ->assertDontSee('XYZ-9')
        ->assertDontSee('sk_test');
});

it('does not show one customer another customer refund', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-UI6'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    $this->actingAs(userWithRole('customer'))
        ->get(route('orders.show', $order))
        ->assertNotFound();
});

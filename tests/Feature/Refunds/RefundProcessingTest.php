<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Domain\Refunds\Actions\VerifyRefund;
use App\Domain\Refunds\Exceptions\RefundNotAllowed;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Product;
use App\Models\Refund;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * What the provider says, and what the platform does about it.
 *
 * The rule under all of this: nothing reaches Succeeded except on the
 * provider's own terminal word. Not an accepted request, not an administrator,
 * not the passage of time.
 *
 * Every service that talks to a provider is resolved after the stubs are
 * installed -- the gateway captures the HTTP factory and the secret key when
 * it is constructed, so one built earlier would try to reach Paystack for
 * real.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->admin = userWithRole('admin');
});

// ------------------------------------------------- What the provider says

it('records an accepted refund as processing, not as done', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    // Paystack queues refunds. Accepting is not sending.
    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-100'));

    $processed = app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($processed->status)->toBe(RefundStatus::Processing)
        ->and($processed->provider_reference)->toBe('RF-100')
        ->and($processed->provider_status)->toBe('pending')
        ->and($processed->succeeded_at)->toBeNull()
        // And the order has not moved: nothing has gone back yet.
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('records a refund the provider has finished as succeeded', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-101'));

    $processed = app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($processed->status)->toBe(RefundStatus::Succeeded)
        ->and($processed->succeeded_at)->not->toBeNull()
        ->and($processed->provider_status)->toBe('processed');
});

it('records a refund the provider refused as failed', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'failed', 'RF-102'));

    $processed = app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($processed->status)->toBe(RefundStatus::Failed)
        ->and($processed->failure_reason)->toContain('failed')
        ->and($processed->failed_at)->not->toBeNull()
        // The order is untouched: a refund that returned nothing changes
        // nothing about it.
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

/*
 * A status nobody has checked is never optimistically read as success. Being
 * wrong in that direction means telling a customer their money is back when it
 * is not, and that message cannot be taken back.
 */
it('treats a status it does not recognise as still in flight', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'some-new-state', 'RF-103'));

    expect(app(ProcessRefund::class)->handle($refund, $this->admin)->status)
        ->toBe(RefundStatus::Processing);
});

it('treats a reversed refund as a failure', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'reversed', 'RF-104'));

    expect(app(ProcessRefund::class)->handle($refund, $this->admin)->status)
        ->toBe(RefundStatus::Failed);
});

// ------------------------------------------------------- Provider trouble

it('records a provider rejection rather than throwing', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakeHttp([
        'api.paystack.co/refund' => Http::response(
            ['status' => false, 'message' => 'Transaction has been fully reversed'],
            400,
        ),
    ]);

    $processed = app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($processed->status)->toBe(RefundStatus::Failed)
        ->and($processed->failure_reason)->toContain('fully reversed');
});

it('records an unreachable provider rather than throwing', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('timed out')]);

    $processed = app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($processed->status)->toBe(RefundStatus::Failed)
        ->and($processed->failure_reason)->not->toBeEmpty();
});

/*
 * The provider describing something other than what we asked for is either a
 * bug or something worse. Never "close enough" where money is concerned.
 */
it('refuses a provider answer for a different amount', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor - 1, 'processed', 'RF-105'));

    $processed = app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($processed->status)->toBe(RefundStatus::Failed)
        ->and($processed->failure_reason)->toContain('different amount');
});

it('refuses a provider answer in a different currency', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-106', 'NGN'));

    $processed = app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($processed->status)->toBe(RefundStatus::Failed)
        ->and($processed->failure_reason)->toContain('NGN');
});

// ------------------------------------------------------------ The order

it('closes a fully refunded paid order as refunded', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-107'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('leaves a partly refunded order where it was', function (): void {
    $order = blockedPaidOrder();
    $part = Money::fromMinor(1_000);

    $refund = requestRefund($order, $part);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-108'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    // Money is still outstanding to this customer, so the order has not
    // finished being refunded.
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

/*
 * The rule from Stage 8, still holding. An order that closed as expired or
 * cancelled keeps the status it closed with -- the refund record says the
 * money went back, and the order still says why it closed.
 */
it('never resurrects or relabels a closed order', function (string $case): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    $payment = initializePayment($order);

    if ($case === 'expired') {
        $this->travel(2)->hours();
        app(OrderLifecycle::class)->expire($order->fresh());
        $expected = OrderStatus::PaymentExpired;
    } else {
        app(OrderLifecycle::class)->cancel($order->fresh(), 'Withdrawn.');
        $expected = OrderStatus::Cancelled;
    }

    payOrder($order->fresh(), $payment->fresh());

    $refund = requestRefund($order->fresh());

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-109'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($order->fresh()->status)->toBe($expected)
        ->and($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
})->with(['expired', 'cancelled']);

// ------------------------------------------ The original payment survives

it('leaves the original payment exactly as it was', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    // Read through the model's casts rather than `only()`, which hands back
    // the raw database strings and would compare a string to an integer.
    $before = [
        'amount_minor' => $payment->amount_minor,
        'currency' => $payment->currency,
        'provider_reference' => $payment->provider_reference,
        'paid_at' => $payment->paid_at,
    ];

    $refund = requestRefund($order);
    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-110'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    $after = $payment->fresh();

    expect($after->amount_minor)->toBe($before['amount_minor'])
        ->and($after->currency)->toBe($before['currency'])
        ->and($after->status)->toBe(OrderPaymentStatus::Success)
        ->and($after->provider_reference)->toBe($before['provider_reference'])
        ->and($after->paid_at->equalTo($before['paid_at']))->toBeTrue()
        // The two questions have two answers, and both are still answerable.
        // Cast: a SUM over a BIGINT comes back from MySQL as a string.
        ->and((int) $after->refunds()->succeeded()->sum('amount_minor'))->toBe($refund->amount_minor);
});

// ------------------------------------------------------------- Reconciling

it('settles a processing refund once the provider finishes it', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-111'));
    $processing = app(ProcessRefund::class)->handle($refund, $this->admin);

    expect($processing->status)->toBe(RefundStatus::Processing);

    // Later, the provider has finished.
    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'pending', 'RF-111'),
        paystackRefundBody($refund->amount_minor, 'processed', 'RF-111'),
    );

    $settled = app(VerifyRefund::class)->handle($processing->fresh());

    expect($settled->status)->toBe(RefundStatus::Succeeded)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('leaves a refund alone while the provider is still deciding', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-112'));
    $processing = app(ProcessRefund::class)->handle($refund, $this->admin);

    // Weeks pass and the provider has still not decided. There is no timeout
    // after which the platform assumes the money went back.
    $this->travel(30)->days();

    expect(app(VerifyRefund::class)->handle($processing->fresh())->status)
        ->toBe(RefundStatus::Processing);
});

it('does not mark a refund failed because the provider was unreachable', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-113'));
    $processing = app(ProcessRefund::class)->handle($refund, $this->admin);

    fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('down')]);

    // Not knowing is not the same as failing, and a lie the next sweep would
    // have to take back is worse than waiting.
    expect(app(VerifyRefund::class)->handle($processing->fresh())->status)
        ->toBe(RefundStatus::Processing);
});

// ------------------------------------------------------------ Idempotency

it('will not send the same refund to the provider twice', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-114'));

    app(ProcessRefund::class)->handle($refund, $this->admin);

    // Re-sending one the provider has already accepted is the single action
    // that could return money twice, so it is refused outright.
    expect(fn (): Refund => app(ProcessRefund::class)->handle($refund->fresh(), $this->admin))
        ->toThrow(RefundNotAllowed::class, 'not waiting');
});

it('settles a refund once however many reconciliations run', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-115'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'pending', 'RF-115'),
        paystackRefundBody($refund->amount_minor, 'processed', 'RF-115'),
    );

    foreach (range(1, 4) as $ignored) {
        app(VerifyRefund::class)->handle($refund->fresh());
    }

    expect(Refund::count())->toBe(1)
        ->and($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        // And the order transitioned exactly once.
        ->and($order->fresh()->transitions()->where('to_status', OrderStatus::Refunded)->count())
        ->toBe(1);
});

<?php

declare(strict_types=1);

use App\Domain\Refunds\Actions\ProcessRefund;
use App\Domain\Refunds\Services\RefundReconciler;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

/*
 * Reconciliation detects. It does not repair.
 *
 * The same principle as the credit ledger's: an automatic repair is a guess
 * about which of two disagreeing records is right, made by the code whose bug
 * may have caused the disagreement. Every test here checks both halves -- that
 * the anomaly is reported, and that nothing was changed.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->admin = userWithRole('admin');
});

// ---------------------------------------------------- Settling what is open

it('settles a refund the provider has finished', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-R1'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'pending', 'RF-R1'),
        paystackRefundBody($refund->amount_minor, 'processed', 'RF-R1'),
    );

    $this->artisan('refunds:reconcile', ['--skip-report' => true])->assertSuccessful();

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('settles an outstanding refund once however many times it runs', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-R2'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'pending', 'RF-R2'),
        paystackRefundBody($refund->amount_minor, 'processed', 'RF-R2'),
    );

    foreach (range(1, 3) as $ignored) {
        $this->artisan('refunds:reconcile', ['--skip-report' => true])->assertSuccessful();
    }

    expect(Refund::count())->toBe(1)
        ->and($order->fresh()->transitions()->where('to_status', OrderStatus::Refunded)->count())
        ->toBe(1);
});

it('leaves a settled refund alone', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-R3'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    $settledAt = $refund->fresh()->succeeded_at;

    // The provider now claims something different. A settled refund is
    // historical fact and is not rewritten by a later answer -- it is
    // reported instead.
    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'failed', 'RF-R3'),
        paystackRefundBody($refund->amount_minor, 'failed', 'RF-R3'),
    );

    $this->artisan('refunds:reconcile');

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->fresh()->succeeded_at->equalTo($settledAt))->toBeTrue();
});

// -------------------------------------------------------- What it reports

it('reports a success the provider will not confirm', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-R4'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'failed', 'RF-R4'),
        paystackRefundBody($refund->amount_minor, 'failed', 'RF-R4'),
    );

    $anomalies = app(RefundReconciler::class)->report();

    expect(collect($anomalies)->pluck('type'))->toContain('unconfirmed_success')
        // Reported, not repaired.
        ->and($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
});

it('reports a refund the provider gave us no reference for', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    Refund::factory()->processing(providerReference: null)->create([
        'order_id' => $order->id,
        'order_payment_id' => $payment->id,
        'amount_minor' => 1_000,
        'requested_by' => $this->admin->id,
    ]);

    $anomalies = app(RefundReconciler::class)->report(askProvider: false);

    expect(collect($anomalies)->pluck('type'))->toContain('untracked_processing');
});

it('reports refunds that sum past the payment they are against', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    // Written straight to the table: the application cannot produce this, and
    // that is precisely why reconciliation checks for it.
    foreach ([1, 2] as $n) {
        DB::table('refunds')->insert([
            'order_id' => $order->id,
            'order_payment_id' => $payment->id,
            'provider' => 'paystack',
            'amount_minor' => $payment->amount_minor,
            'currency' => 'GHS',
            'status' => RefundStatus::Succeeded->value,
            'reason' => 'other',
            'requested_by' => $this->admin->id,
            'idempotency_key' => 'over-refund-'.$n,
            'requested_at' => now(),
            'succeeded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $anomalies = app(RefundReconciler::class)->report(askProvider: false);

    expect(collect($anomalies)->pluck('type'))->toContain('over_refunded');
});

it('reports a refund that has been in flight far too long', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    $refund = Refund::factory()->processing('RF-STALL')->create([
        'order_id' => $order->id,
        'order_payment_id' => $payment->id,
        'amount_minor' => 1_000,
        'requested_by' => $this->admin->id,
    ]);

    $refund->processed_at = now()->subDays(10);
    $refund->save();

    $anomalies = app(RefundReconciler::class)->report(askProvider: false);

    expect(collect($anomalies)->pluck('type'))->toContain('stalled')
        // Nothing is concluded from it having taken a long time.
        ->and($refund->fresh()->status)->toBe(RefundStatus::Processing);
});

it('reports nothing when everything agrees', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-R5'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    fakePaystackRefund(
        paystackRefundBody($refund->amount_minor, 'processed', 'RF-R5'),
        paystackRefundBody($refund->amount_minor, 'processed', 'RF-R5'),
    );

    expect(app(RefundReconciler::class)->report())->toBe([]);
});

// ------------------------------------------------------------- The command

it('exits successfully when nothing disagrees', function (): void {
    $this->artisan('refunds:reconcile')->assertSuccessful();
});

it('exits non-zero when something does', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    Refund::factory()->processing(providerReference: null)->create([
        'order_id' => $order->id,
        'order_payment_id' => $payment->id,
        'amount_minor' => 1_000,
        'requested_by' => $this->admin->id,
    ]);

    // A monitor has to be able to notice without reading logs.
    $this->artisan('refunds:reconcile')->assertFailed();
});

it('carries on when one refund cannot be checked', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    Refund::factory()->processing('RF-BAD')->create([
        'order_id' => $order->id,
        'order_payment_id' => $payment->id,
        'amount_minor' => 1_000,
        'requested_by' => $this->admin->id,
    ]);

    fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('down')]);

    // One unreachable record must not stop everybody else's money being
    // confirmed, and an unreachable provider is not a failed refund.
    $this->artisan('refunds:reconcile', ['--skip-report' => true])->assertSuccessful();

    expect(Refund::first()->status)->toBe(RefundStatus::Processing);
});

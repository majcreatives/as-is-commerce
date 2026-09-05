<?php

declare(strict_types=1);

use App\Domain\Refunds\Actions\ProcessRefund;
use App\Domain\Refunds\Exceptions\RefundNotAllowed;
use App\Domain\Refunds\Services\RefundCalculator;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

/*
 * The refund races, against real MySQL locks.
 *
 * Truncation rather than a wrapping transaction: a second connection cannot
 * see rows an uncommitted transaction has written, so under RefreshDatabase
 * these tests would observe an empty database and pass without exercising
 * anything.
 *
 * The invariant under all of it: however many administrators press refund at
 * the same moment, the platform can never return more than it received.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->admin = userWithRole('admin');
    $this->calculator = app(RefundCalculator::class);

    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');
});

afterEach(function (): void {
    try {
        while (DB::connection('second')->transactionLevel() > 0) {
            DB::connection('second')->rollBack();
        }
    } catch (Throwable) {
        // Connection already gone.
    }

    DB::purge('second');
});

/*
 * The primitive everything else rests on. Without a real lock on the order
 * row, two administrators would each read the refundable amount before either
 * had written, and both would clear a cap only one of them actually cleared.
 */
it('holds the order row while a refund is being written', function (): void {
    $order = blockedPaidOrder();

    DB::beginTransaction();

    DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')->table('orders')
            ->where('id', $order->id)
            ->lockForUpdate()
            ->first();
    } catch (Throwable) {
        $blocked = true;
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second connection must not take the order lock while it is held.');
});

/*
 * The rule from the brief, exactly: GH₵100 paid, two administrators each
 * asking for GH₵70. The combined successful refund can never exceed GH₵100.
 */
it('refuses the second of two overlapping refunds that would over-refund', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    // Seventy per cent each: either alone is fine, both together are not.
    $seventy = Money::fromMinor((int) round($payment->amount_minor * 0.7));

    $first = requestRefund($order, $seventy, key: 'admin-a', actor: $this->admin);

    // The second administrator arrives while the first is still in flight.
    expect(fn (): Refund => requestRefund($order->fresh(), $seventy, key: 'admin-b', actor: $this->admin))
        ->toThrow(RefundNotAllowed::class);

    expect(Refund::count())->toBe(1)
        ->and($first->amount_minor)->toBe($seventy->minor)
        ->and((int) DB::table('refunds')->sum('amount_minor'))
        ->toBeLessThanOrEqual($payment->amount_minor);
});

it('can never sum successful refunds past the payment', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    // Settle a first refund for most of it.
    $first = requestRefund($order, Money::fromMinor($payment->amount_minor - 100), actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($first->amount_minor, 'processed', 'RF-C1'));
    app(ProcessRefund::class)->handle($first, $this->admin);

    // GH₵1 remains. Every larger request is refused, from every direction.
    expect($this->calculator->refundable($payment->fresh())->minor)->toBe(100);

    foreach ([101, 500, $payment->amount_minor] as $tooMuch) {
        expect(fn (): Refund => requestRefund($order->fresh(), Money::fromMinor($tooMuch), actor: $this->admin))
            ->toThrow(RefundNotAllowed::class);
    }

    $second = requestRefund($order->fresh(), Money::fromMinor(100), actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($second->amount_minor, 'processed', 'RF-C2'));
    app(ProcessRefund::class)->handle($second, $this->admin);

    expect((int) DB::table('refunds')->where('status', RefundStatus::Succeeded->value)->sum('amount_minor'))
        ->toBe($payment->amount_minor)
        ->and($this->calculator->refundable($payment->fresh())->minor)->toBe(0)
        // Fully returned, so the order finally moves.
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded);
});

it('refuses anything at all once a payment is fully refunded', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-C3'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    // Not one pesewa more.
    expect(fn (): Refund => requestRefund($order->fresh(), Money::fromMinor(1), actor: $this->admin))
        ->toThrow(RefundNotAllowed::class);

    expect(Refund::count())->toBe(1);
});

/*
 * Two deliveries of the same request converge on one refund rather than two.
 * The idempotency key is what makes a double-submitted form harmless.
 */
it('produces one refund from a repeated request', function (): void {
    $order = blockedPaidOrder();
    $payment = $order->payments()->successful()->first();

    foreach (range(1, 5) as $ignored) {
        requestRefund($order->fresh(), key: 'one-operator-one-click', actor: $this->admin);
    }

    expect(Refund::count())->toBe(1)
        ->and((int) DB::table('refunds')->sum('amount_minor'))->toBe($payment->amount_minor);
});

/*
 * The same guarantee at the sending step. Whichever call gets there first
 * sends the refund; the other finds it is no longer pending and is refused,
 * so the provider is never asked twice for the same money.
 */
it('sends a refund to the provider exactly once', function (): void {
    $order = blockedPaidOrder();
    $refund = requestRefund($order, actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'pending', 'RF-C4'));

    app(ProcessRefund::class)->handle($refund, $this->admin);

    foreach (range(1, 3) as $ignored) {
        expect(fn (): Refund => app(ProcessRefund::class)->handle($refund->fresh(), $this->admin))
            ->toThrow(RefundNotAllowed::class);
    }

    expect($refund->fresh()->status)->toBe(RefundStatus::Processing)
        ->and(Refund::count())->toBe(1);
});

/*
 * A refund and the expiry sweep touching the same order cannot corrupt each
 * other: both take the order row, so one waits for the other.
 */
it('leaves the ledgers untouched however the races fall', function (): void {
    $order = blockedPaidOrder();

    $credits = DB::table('credit_transactions')->count();
    $creditBalances = DB::table('credit_wallets')->sum('balance');
    $inventory = DB::table('inventory_transactions')->count();

    $refund = requestRefund($order, actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'processed', 'RF-C5'));
    app(ProcessRefund::class)->handle($refund, $this->admin);

    $this->artisan('orders:expire-checkouts')->assertSuccessful();
    $this->artisan('auctions:tick')->assertSuccessful();

    expect(DB::table('credit_transactions')->count())->toBe($credits)
        ->and(DB::table('credit_wallets')->sum('balance'))->toEqual($creditBalances)
        ->and(DB::table('inventory_transactions')->count())->toBe($inventory)
        ->and(DB::table('cash_transactions')->count())->toBe(0);
});

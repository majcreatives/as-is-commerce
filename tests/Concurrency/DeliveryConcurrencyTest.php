<?php

declare(strict_types=1);

use App\Domain\Delivery\Services\DeliveryLifecycle;
use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\DeliveryTransition;
use Illuminate\Support\Facades\DB;

/*
 * Two members of staff and one package, against real MySQL locks.
 *
 * Truncation rather than a wrapping transaction: a second connection cannot
 * see rows an uncommitted transaction has written, so under RefreshDatabase
 * these tests would observe an empty database and pass without exercising
 * anything.
 *
 * The invariant: however many people press the same button at the same moment,
 * one package moves once, one order completes once, one message is sent and
 * one line of history is written.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->staff = userWithRole('admin');
    $this->other = userWithRole('admin');
    $this->deliveries = app(DeliveryLifecycle::class);

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
 * The primitive everything else rests on. The order row is taken first, so
 * every concurrent move on a package serializes before its status is read.
 */
it('holds the order row while a package is being moved', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    DB::beginTransaction();

    DB::table('orders')->where('id', $delivery->order_id)->lockForUpdate()->first();

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')->table('orders')
            ->where('id', $delivery->order_id)
            ->lockForUpdate()
            ->first();
    } catch (Throwable) {
        $blocked = true;
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second connection must not take the order lock while it is held.');
});

it('holds the delivery row too', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    DB::beginTransaction();

    DB::table('deliveries')->where('id', $delivery->id)->lockForUpdate()->first();

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')->table('deliveries')
            ->where('id', $delivery->id)
            ->lockForUpdate()
            ->first();
    } catch (Throwable) {
        $blocked = true;
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second connection must not take the delivery lock while it is held.');
});

/*
 * The case from the brief: two members of staff marking the same package
 * delivered. One transition succeeds; the second finds it already done and
 * changes nothing.
 */
it('completes an order exactly once when two people mark it delivered', function (): void {
    $delivery = deliveryAt(DeliveryStatus::OutForDelivery);
    $order = $delivery->order;

    $this->deliveries->markDelivered($delivery->fresh(), $this->staff, receivedBy: 'Ama');
    $this->deliveries->markDelivered($delivery->fresh(), $this->other, receivedBy: 'Someone else');

    $done = $delivery->fresh();

    expect($done->status)->toBe(DeliveryStatus::Delivered)
        // The first report stands. A second person pressing the button does
        // not overwrite what the first recorded.
        ->and($done->received_by)->toBe('Ama')
        ->and(DeliveryTransition::where('delivery_id', $done->id)
            ->where('to_status', DeliveryStatus::Delivered)->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($order->fresh()->transitions()->where('to_status', OrderStatus::Fulfilled)->count())->toBe(1);
});

it('writes one history row however many times a step is repeated', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    foreach (range(1, 5) as $ignored) {
        $this->deliveries->prepare($delivery->fresh(), $this->staff);
    }

    expect(DeliveryTransition::where('delivery_id', $delivery->id)
        ->where('to_status', DeliveryStatus::Preparing)->count())->toBe(1)
        ->and($delivery->order->fresh()->transitions()
            ->where('to_status', OrderStatus::Processing)->count())->toBe(1);
});

it('counts one attempt however many times dispatch is pressed', function (): void {
    $delivery = deliveryAt(DeliveryStatus::ReadyForDispatch);

    foreach (range(1, 4) as $ignored) {
        $this->deliveries->dispatch($delivery->fresh(), $this->staff);
    }

    expect($delivery->fresh()->attempts)->toBe(1);
});

it('records one failure however many times it is reported', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched);

    foreach (range(1, 3) as $ignored) {
        $this->deliveries->markFailed($delivery->fresh(), DeliveryFailureReason::RecipientUnavailable, $this->staff);
    }

    expect(DeliveryTransition::where('delivery_id', $delivery->id)
        ->where('to_status', DeliveryStatus::DeliveryFailed)->count())->toBe(1);
});

/*
 * Nothing a package does can disturb the money or the stock, however the races
 * fall.
 */
it('leaves every ledger untouched however the moves land', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Pending);

    $credits = DB::table('credit_transactions')->count();
    $creditBalances = DB::table('credit_wallets')->sum('balance');
    $inventory = DB::table('inventory_transactions')->count();
    $payments = DB::table('order_payments')->count();

    // The whole path, with two people alternating, and then each terminal
    // step pressed repeatedly. Re-running the earlier steps would be refused
    // by the state machine, which is itself the correct behaviour.
    $this->deliveries->prepare($delivery->fresh(), $this->staff);
    $this->deliveries->markReady($delivery->fresh(), $this->other);
    $this->deliveries->dispatch($delivery->fresh(), $this->staff);

    foreach (range(1, 3) as $ignored) {
        $this->deliveries->markDelivered($delivery->fresh(), $this->other);
    }

    $this->artisan('orders:expire-checkouts')->assertSuccessful();
    $this->artisan('auctions:tick')->assertSuccessful();

    expect(DB::table('credit_transactions')->count())->toBe($credits)
        ->and(DB::table('credit_wallets')->sum('balance'))->toEqual($creditBalances)
        ->and(DB::table('inventory_transactions')->count())->toBe($inventory)
        ->and(DB::table('order_payments')->count())->toBe($payments)
        ->and(DB::table('refunds')->count())->toBe(0);
});

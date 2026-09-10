<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\StoreWallet\Services\StoreWalletCheckout;
use App\Domain\StoreWallet\Services\StoreWalletLedgerService;
use App\Models\Product;
use App\Models\StoreWalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Store Wallet value is spendable, and spending money in parallel has to be
 * safe in the same way the credit ledger is: a wallet row lock taken before
 * the balance is read. Against real MySQL locks, because a second connection
 * cannot see rows an uncommitted transaction has written.
 */

beforeEach(function (): void {
    seedRoles();
    seedSettings();

    $this->wallets = app(StoreWalletLedgerService::class);

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
 * The primitive everything else rests on. If the store_wallets row lock does
 * not actually serialize access, two simultaneous checkouts could each read
 * the same starting balance and both plan to spend it.
 */
it('serializes access to a store wallet row across connections', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 300);
    $walletId = $this->wallets->walletFor($user)->id;

    // Connection one takes the lock and holds it.
    DB::beginTransaction();
    DB::table('store_wallets')->where('id', $walletId)->lockForUpdate()->first();

    // Connection two gives up quickly rather than waiting the default 50s.
    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')->table('store_wallets')
            ->where('id', $walletId)
            ->lockForUpdate()
            ->first();
    } catch (QueryException $e) {
        // 1205: lock wait timeout. The second connection was made to wait,
        // which is exactly the behaviour that prevents double-spending.
        $blocked = str_contains($e->getMessage(), '1205')
            || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second connection must not acquire the store wallet lock while it is held.');
});

/*
 * The overspend scenario: one balance, two catalogue checkouts, each asking
 * for more than half. Whatever the interleaving, the two orders may commit no
 * more than the wallet held, and the wallet may never go below zero.
 */
it('never lets two checkouts spend the same Store Wallet value', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 300);

    $first = Product::factory()->active()->pricedAt(550_000)->create();
    $second = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($first, 1);
    app(InventoryService::class)->initialStock($second, 1);

    $firstOrder = buyNowCheckout($user, $first);
    $secondOrder = buyNowCheckout($user, $second);

    $applied = $firstOrder->store_wallet_applied_minor + $secondOrder->store_wallet_applied_minor;

    expect($applied)->toBeLessThanOrEqual(300)
        ->and($firstOrder->payable_minor)->toBeGreaterThan(0)
        ->and($secondOrder->payable_minor)->toBeGreaterThan(0)
        ->and(storeWalletBalance($user)->minor)->toBeGreaterThanOrEqual(0)
        ->and($this->wallets->verify($this->wallets->walletFor($user)))
        ->toMatchArray(['matches' => true]);
});

/*
 * The unique idempotency key is the lock that guards release: two sweeps
 * expiring the same checkout concurrently converge on one release row.
 */
it('records one release for two concurrent releases of the same checkout', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 300);

    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout($user, $product);

    $checkout = app(StoreWalletCheckout::class);
    $checkout->release($order->fresh());
    $checkout->release($order->fresh());

    expect(StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::releasedKeyFor($order->id))->count())
        ->toBe(1)
        ->and(storeWalletBalance($user)->minor)->toBe(300);
});

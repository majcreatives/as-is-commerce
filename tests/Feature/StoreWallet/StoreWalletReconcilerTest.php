<?php

declare(strict_types=1);

use App\Domain\StoreWallet\Services\StoreWalletReconciler;
use App\Domain\StoreWallet\Services\StoreWalletReport;
use App\Models\StoreWallet;

/*
 * The Store Wallet ledger reconciler.
 *
 * It detects and reports. These tests prove it finds the anomalies it is
 * designed to catch and leaves healthy wallets alone.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->reconciler = app(StoreWalletReconciler::class);
});

it('reports a healthy wallet as healthy', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $wallet = StoreWallet::where('user_id', $user->id)->first();
    $report = $this->reconciler->reconcile($wallet);

    expect($report)->toBeInstanceOf(StoreWalletReport::class)
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->problemCount())->toBe(0);
});

it('detects a projection divergence', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $wallet = StoreWallet::where('user_id', $user->id)->first();
    $wallet->permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance_minor = 999;
        $wallet->save();
    });

    $report = $this->reconciler->reconcile($wallet);

    expect($report->isHealthy())->toBeFalse()
        ->and(implode("\n", $report->problems))->toContain('does not equal the sum of ledger amounts');
});

it('detects a running balance discontinuity', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $wallet = StoreWallet::where('user_id', $user->id)->first();

    // A transaction whose balance_after disagrees with a replay of the
    // ledger. Inserted rather than updated: history is append-only, so a
    // discontinuity introduced after the fact is a compromise of one row, not
    // a fix-up of an old one.
    DB::table('store_wallet_transactions')->insert([
        'store_wallet_id' => $wallet->id,
        'type' => 'auction_loss_compensation',
        'amount_minor' => 100,
        'balance_after_minor' => 999,
        'currency' => 'GHS',
        'created_at' => now(),
    ]);

    $report = $this->reconciler->reconcile($wallet);

    expect($report->isHealthy())->toBeFalse()
        ->and(implode("\n", $report->problems))->toContain('replayed balance');
});

it('detects a mis-signed transaction', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $wallet = StoreWallet::where('user_id', $user->id)->first();

    // Insert a negative-amount transaction for a credit type.
    DB::table('store_wallet_transactions')->insert([
        'store_wallet_id' => $wallet->id,
        'type' => 'auction_loss_compensation',
        'amount_minor' => -100,
        'balance_after_minor' => 0,
        'currency' => 'GHS',
        'created_at' => now(),
    ]);

    $report = $this->reconciler->reconcile($wallet);

    expect($report->isHealthy())->toBeFalse()
        ->and(implode("\n", $report->problems))->toContain('does not match its type');
});

it('detects a missing reference on an issuance', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $wallet = StoreWallet::where('user_id', $user->id)->first();

    // Insert an issuance without a reference.
    DB::table('store_wallet_transactions')->insert([
        'store_wallet_id' => $wallet->id,
        'type' => 'auction_loss_compensation',
        'amount_minor' => 100,
        'balance_after_minor' => 600,
        'currency' => 'GHS',
        'created_at' => now(),
    ]);

    $report = $this->reconciler->reconcile($wallet);

    expect($report->isHealthy())->toBeFalse()
        ->and(implode("\n", $report->problems))->toContain('missing a reference');
});

it('detects a missing reference on an order transaction', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $wallet = StoreWallet::where('user_id', $user->id)->first();

    // Insert an order_applied without a reference.
    DB::table('store_wallet_transactions')->insert([
        'store_wallet_id' => $wallet->id,
        'type' => 'order_applied',
        'amount_minor' => -100,
        'balance_after_minor' => 400,
        'currency' => 'GHS',
        'created_at' => now(),
    ]);

    $report = $this->reconciler->reconcile($wallet);

    expect($report->isHealthy())->toBeFalse()
        ->and(implode("\n", $report->problems))->toContain('missing a reference')
        ->toContain('Order');
});

it('returns only unhealthy wallets from reconcileAll', function (): void {
    $healthy = userWithRole('customer');
    fundStoreWallet($healthy, 500);

    $unhealthy = userWithRole('customer');
    fundStoreWallet($unhealthy, 500);

    $wallet = StoreWallet::where('user_id', $unhealthy->id)->first();
    $wallet->permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance_minor = 0;
        $wallet->save();
    });

    $reports = $this->reconciler->reconcileAll();

    expect($reports)->toHaveCount(1)
        ->and($reports->first()->userId)->toBe($unhealthy->id);
});

it('detects projection mismatches via the cheap query', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $wallet = StoreWallet::where('user_id', $user->id)->first();
    $wallet->permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance_minor = 0;
        $wallet->save();
    });

    $mismatches = $this->reconciler->projectionMismatches();

    expect($mismatches)->toHaveCount(1)
        ->and($mismatches->first()->id)->toBe($wallet->id);
});

it('returns no projection mismatches for healthy wallets', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    expect($this->reconciler->projectionMismatches())->toHaveCount(0);
});

<?php

declare(strict_types=1);

use App\Livewire\Admin\StoreWallets\StoreWalletManager;
use App\Models\StoreWallet;
use App\Models\User;
use Livewire\Livewire;

/*
 * The Store Wallet admin screen.
 *
 * Read-only and authorized: every balance shown is derived from the immutable
 * ledger, and every anomaly is computed from a bounded query.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

// --------------------------------------------------------------- Access

it('is reachable by an administrator', function (): void {
    $this->actingAs($this->admin)->get(route('admin.store-wallets.index'))->assertOk();
});

it('is refused to a customer', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.store-wallets.index'))
        ->assertForbidden();
});

it('is refused to staff without the permission', function (): void {
    Livewire::actingAs(staffWith(['orders.view']))
        ->test(StoreWalletManager::class)
        ->assertForbidden();
});

it('is refused to a guest', function (): void {
    $this->get(route('admin.store-wallets.index'))->assertRedirect(route('login'));
});

// --------------------------------------------------------------- Read-only

it('exposes only mount, render and filter hooks', function (): void {
    $own = array_filter(
        get_class_methods(StoreWalletManager::class),
        fn (string $method): bool => in_array($method, ['mount', 'render', 'updatedSearch'], true),
    );

    expect(array_values($own))->toEqualCanonicalizing(['mount', 'render', 'updatedSearch']);
});

// --------------------------------------------------------------- Correctness

it('lists store wallets with user and balance', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    Livewire::actingAs($this->admin)
        ->test(StoreWalletManager::class)
        ->assertOk()
        ->assertSee($user->name)
        ->assertSee('GH₵ 5.00');
});

it('shows an empty state when no store wallets exist', function (): void {
    Livewire::actingAs($this->admin)
        ->test(StoreWalletManager::class)
        ->assertOk()
        ->assertSee('No store wallets');
});

it('reports a healthy ledger', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    Livewire::actingAs($this->admin)
        ->test(StoreWalletManager::class)
        ->assertSee('Healthy');
});

it('detects a projection divergence', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    // Tamper with the balance column to create a divergence.
    $wallet = StoreWallet::where('user_id', $user->id)->first();
    $wallet->permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance_minor = 999;
        $wallet->save();
    });

    Livewire::actingAs($this->admin)
        ->test(StoreWalletManager::class)
        ->assertSee('1 issue')
        ->assertSee('does not equal the sum of ledger amounts');
});

it('detects a mis-signed transaction', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    // Insert a transaction with a negative amount on a credit type via DB.
    $wallet = StoreWallet::where('user_id', $user->id)->first();
    DB::table('store_wallet_transactions')->insert([
        'store_wallet_id' => $wallet->id,
        'type' => 'auction_loss_compensation',
        'amount_minor' => -100,
        'balance_after_minor' => 0,
        'currency' => 'GHS',
        'created_at' => now(),
    ]);

    Livewire::actingAs($this->admin)
        ->test(StoreWalletManager::class)
        ->assertSee('does not match its type');
});

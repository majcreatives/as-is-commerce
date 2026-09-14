<?php

declare(strict_types=1);

use App\Domain\Credit\Services\CreditLedgerService;
use App\Enums\CreditTransactionType;
use App\Livewire\Wallet\WalletOverview;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->customer = userWithRole('customer');
    $this->ledger = app(CreditLedgerService::class);
});

// ------------------------------------------------------------------ Access

it('requires authentication', function (): void {
    $this->get('/wallet')->assertRedirect(route('login'));
});

it('lets a signed-in customer see their wallet', function (): void {
    $this->actingAs($this->customer)->get('/wallet')->assertOk();
});

// ----------------------------------------------------------- Honest zeroes

/*
 * A new account really does hold nothing. Showing zero is reporting the truth,
 * not a placeholder -- and there is no demo data anywhere that would make it
 * show otherwise.
 */
it('shows a genuine zero balance for a new account', function (): void {
    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertOk()
        ->assertSee('0')
        ->assertSee('0.00')
        ->assertSee('No credit activity yet');
});

it('shows empty states rather than invented activity', function (): void {
    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertSee('No credit activity yet')
        ->set('tab', 'store_wallet')
        ->assertSee('No Store Wallet activity yet')
        ->set('tab', 'lots')
        ->assertSee('No credit batches');
});

// -------------------------------------------------------------- Real data

it('shows the real credit balance', function (): void {
    grantCredits($this->customer, 250);

    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertSee('250');
});

it('lists credit transactions with their type and signed amount', function (): void {
    grantCredits($this->customer, 100, CreditTransactionType::PromotionalCredit);
    $this->ledger->consumeCredits(creditWalletFor($this->customer), 40);

    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertSee('Promotional credits')
        ->assertSee('+100')
        ->assertSee('-40');
});

it('shows credit batches with their source and expiry', function (): void {
    grantCredits($this->customer, 100, CreditTransactionType::PromotionalCredit, now()->addMonth());
    grantCredits($this->customer, 50, CreditTransactionType::Purchase);

    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->set('tab', 'lots')
        ->assertSee('Promotional')
        ->assertSee('Purchased')
        ->assertSee('Does not expire');
});

it('warns about credits expiring soon', function (): void {
    grantCredits($this->customer, 75, CreditTransactionType::PromotionalCredit, now()->addWeek());

    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertSee('expiring within a month');
});

it('does not warn when nothing is expiring', function (): void {
    grantCredits($this->customer, 75, CreditTransactionType::Purchase);

    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertDontSee('expiring within a month');
});

it('shows the real Store Wallet balance and history', function (): void {
    fundStoreWallet($this->customer, 15_000);

    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertSee('150.00')
        ->set('tab', 'store_wallet')
        ->assertSee('Auction credit value');
});

// ------------------------------------------------------------- Separation

it('never shows another user their wallet', function (): void {
    $other = User::factory()->create();
    grantCredits($other, 9_999);

    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertDontSee('9,999');
});

it('excludes expired credits from the spendable balance shown', function (): void {
    grantCredits($this->customer, 60, CreditTransactionType::PromotionalCredit, now()->subDay());
    grantCredits($this->customer, 40, CreditTransactionType::Purchase);

    // Spendable is 40, even though the ledger still records 100.
    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertSet('tab', 'credits')
        ->assertSee('40');

    expect(creditWalletFor($this->customer)->fresh()->spendableBalance())->toBe(40);
});

// -------------------------------------------------------------- Read-only

/*
 * Buying credits needs payment processing, which is a later stage. Nothing on
 * this page may change a balance.
 */
it('offers no way to change a balance', function (): void {
    Livewire::actingAs($this->customer)
        ->test(WalletOverview::class)
        ->assertDontSee('Buy credits')
        ->assertDontSee('Add funds')
        ->assertDontSee('Adjust');
});

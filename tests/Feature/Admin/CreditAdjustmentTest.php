<?php

declare(strict_types=1);

use App\Domain\Credit\Actions\AdjustCredits;
use App\Domain\Credit\Exceptions\InsufficientCredits;
use App\Domain\Credit\Exceptions\InvalidLedgerOperation;
use App\Enums\CreditLotSource;
use App\Enums\CreditTransactionType;
use App\Livewire\Admin\Wallets\WalletDetail;
use App\Livewire\Admin\Wallets\WalletIndex;
use App\Models\CreditLot;
use App\Models\CreditTransaction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->admin = userWithRole('admin');
    $this->customer = userWithRole('customer');
});

// ------------------------------------------------------------- The scenario

/*
 * The worked example from the specification: an admin grants 100 promotional
 * credits, and the system produces a lot, a ledger entry, the resulting
 * balance, and an audit record naming the administrator and the reason.
 */
it('produces a lot, a ledger entry, a balance and an audit record', function (): void {
    $transaction = app(AdjustCredits::class)->handle(
        user: $this->customer,
        amount: 100,
        reason: 'Goodwill gesture after a delivery delay.',
        actor: $this->admin,
        source: CreditLotSource::Promotional,
    );

    expect($transaction->amount)->toBe(100)
        ->and($transaction->balance_after)->toBe(100)
        ->and($transaction->type)->toBe(CreditTransactionType::PromotionalCredit)
        ->and($transaction->created_by)->toBe($this->admin->id)
        ->and($transaction->description)->toBe('Goodwill gesture after a delivery delay.');

    $lot = CreditLot::first();
    expect($lot->source_type)->toBe(CreditLotSource::Promotional)
        ->and($lot->original_amount)->toBe(100)
        ->and($lot->remaining_amount)->toBe(100);

    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(100);

    $entry = Activity::where('log_name', 'credit')->where('description', 'credit_adjusted')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties->get('reason'))->toBe('Goodwill gesture after a delivery delay.')
        ->and($entry->properties->get('amount'))->toBe(100);
});

it('grants credits with an expiry date', function (): void {
    $expiry = now()->addMonth();

    app(AdjustCredits::class)->handle(
        user: $this->customer, amount: 50, reason: 'Launch promotion.',
        actor: $this->admin, source: CreditLotSource::Promotional, expiresAt: $expiry,
    );

    expect(CreditLot::first()->expires_at->timestamp)->toBe($expiry->timestamp);
});

it('takes credits back with a negative adjustment', function (): void {
    grantCredits($this->customer, 100);

    $transaction = app(AdjustCredits::class)->handle(
        user: $this->customer, amount: -30, reason: 'Credits granted in error.',
        actor: $this->admin,
    );

    expect($transaction->amount)->toBe(-30)
        ->and($transaction->balance_after)->toBe(70)
        ->and($transaction->type)->toBe(CreditTransactionType::Reversal)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(70);
});

it('records which lot a negative adjustment drew from', function (): void {
    grantCredits($this->customer, 100);

    $transaction = app(AdjustCredits::class)->handle(
        user: $this->customer, amount: -30, reason: 'Granted in error.', actor: $this->admin,
    );

    expect((int) $transaction->consumptions()->sum('amount'))->toBe(30)
        ->and(CreditLot::first()->fresh()->remaining_amount)->toBe(70);
});

it('refuses to take back more credits than the user holds', function (): void {
    grantCredits($this->customer, 10);

    expect(fn (): CreditTransaction => app(AdjustCredits::class)->handle(
        user: $this->customer, amount: -50, reason: 'Too much.', actor: $this->admin,
    ))->toThrow(InsufficientCredits::class);

    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(10);
});

// -------------------------------------------------------------- Validation

it('requires a reason', function (string $reason): void {
    expect(fn (): CreditTransaction => app(AdjustCredits::class)->handle(
        user: $this->customer, amount: 100, reason: $reason, actor: $this->admin,
    ))->toThrow(InvalidLedgerOperation::class);

    expect(CreditTransaction::count())->toBe(0);
})->with(['empty' => '', 'whitespace only' => '   ']);

it('refuses a zero adjustment', function (): void {
    expect(fn (): CreditTransaction => app(AdjustCredits::class)->handle(
        user: $this->customer, amount: 0, reason: 'Nothing to do.', actor: $this->admin,
    ))->toThrow(InvalidLedgerOperation::class);
});

// ----------------------------------------------------------- Authorization

it('lets an admin adjust credits', function (): void {
    expect($this->admin->can('credits.adjust'))->toBeTrue();
});

it('lets a super admin adjust credits', function (): void {
    expect(userWithRole('super_admin')->can('credits.adjust'))->toBeTrue();
});

it('does not let a customer adjust credits', function (): void {
    expect($this->customer->can('credits.adjust'))->toBeFalse()
        ->and($this->customer->can('wallets.reconcile'))->toBeFalse()
        ->and($this->customer->can('cash.view'))->toBeFalse();
});

it('lets a customer see their own wallet', function (): void {
    expect($this->customer->can('wallets.view'))->toBeTrue()
        ->and($this->customer->can('credits.view'))->toBeTrue();
});

// ------------------------------------------------------------- Admin screens

it('forbids a customer from the admin wallet screens', function (string $route): void {
    $this->actingAs($this->customer)->get($route)->assertForbidden();
})->with([
    'index' => '/admin/wallets',
]);

it('forbids a customer from the wallet detail screen', function (): void {
    $this->actingAs($this->customer)
        ->get(route('admin.wallets.show', $this->customer))
        ->assertForbidden();
});

it('allows an admin into the wallet screens', function (): void {
    $this->actingAs($this->admin)->get('/admin/wallets')->assertOk();
    $this->actingAs($this->admin)->get(route('admin.wallets.show', $this->customer))->assertOk();
});

it('forbids a customer from the wallet index component', function (): void {
    Livewire::actingAs($this->customer)->test(WalletIndex::class)->assertForbidden();
});

it('forbids a customer from the wallet detail component', function (): void {
    Livewire::actingAs($this->customer)
        ->test(WalletDetail::class, ['user' => $this->customer])
        ->assertForbidden();
});

it('finds a user by phone number', function (): void {
    Livewire::actingAs($this->admin)
        ->test(WalletIndex::class)
        ->set('search', substr($this->customer->phone, -6))
        ->assertSee($this->customer->name ?? 'No name given');
});

it('posts an adjustment from the admin screen', function (): void {
    Livewire::actingAs($this->admin)
        ->test(WalletDetail::class, ['user' => $this->customer])
        ->set('adjustmentAmount', 250)
        ->set('adjustmentReason', 'Compensation for a failed auction.')
        ->set('adjustmentSource', 'promotional')
        ->call('adjust')
        ->assertHasNoErrors();

    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(250);
});

it('rejects an adjustment with no reason from the admin screen', function (): void {
    Livewire::actingAs($this->admin)
        ->test(WalletDetail::class, ['user' => $this->customer])
        ->set('adjustmentAmount', 100)
        ->set('adjustmentReason', '')
        ->call('adjust')
        ->assertHasErrors('adjustmentReason');

    expect(CreditTransaction::count())->toBe(0);
});

it('rejects a zero adjustment from the admin screen', function (): void {
    Livewire::actingAs($this->admin)
        ->test(WalletDetail::class, ['user' => $this->customer])
        ->set('adjustmentAmount', 0)
        ->set('adjustmentReason', 'A perfectly good reason.')
        ->call('adjust')
        ->assertHasErrors('adjustmentAmount');
});

it('reports a refused adjustment rather than failing silently', function (): void {
    grantCredits($this->customer, 5);

    Livewire::actingAs($this->admin)
        ->test(WalletDetail::class, ['user' => $this->customer])
        ->set('adjustmentAmount', -500)
        ->set('adjustmentReason', 'More than they hold.')
        ->call('adjust')
        ->assertHasErrors('adjustmentAmount');

    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(5);
});

// ----------------------------------------------------------- Reconciliation

it('runs reconciliation from the admin screen and records who ran it', function (): void {
    grantCredits($this->customer, 100);

    Livewire::actingAs($this->admin)
        ->test(WalletDetail::class, ['user' => $this->customer])
        ->call('reconcile')
        ->assertOk();

    $entry = Activity::where('description', 'wallet_reconciled')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties->get('healthy'))->toBeTrue();
});

it('does not let a customer run reconciliation', function (): void {
    Livewire::actingAs($this->customer)
        ->test(WalletDetail::class, ['user' => $this->customer])
        ->assertForbidden();
});

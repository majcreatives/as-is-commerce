<?php

declare(strict_types=1);

use App\Domain\Credit\Services\CreditLedgerReconciler;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Enums\CreditTransactionType;
use App\Models\CreditLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The reconciler exists to detect drift between the ledger and the things
 * derived from it. Testing it means deliberately corrupting data, which is
 * done here with raw SQL -- the application has no path that could produce
 * these states, which is the point.
 */

beforeEach(function (): void {
    seedRoles();

    $this->ledger = app(CreditLedgerService::class);
    $this->reconciler = app(CreditLedgerReconciler::class);
    $this->user = User::factory()->create();
});

// ------------------------------------------------------------------ Healthy

it('passes a wallet that has never been used', function (): void {
    $report = $this->reconciler->reconcile(creditWalletFor($this->user));

    expect($report->isHealthy())->toBeTrue()
        ->and($report->problems)->toBe([])
        ->and($report->storedBalance)->toBe(0);
});

it('passes a wallet after a realistic sequence of operations', function (): void {
    grantCredits($this->user, 100);
    grantCredits($this->user, 50, CreditTransactionType::PromotionalCredit);
    $this->ledger->consumeCredits(creditWalletFor($this->user), 30);
    $this->ledger->consumeCredits(creditWalletFor($this->user), 45);

    $report = $this->reconciler->reconcile(creditWalletFor($this->user)->fresh());

    expect($report->isHealthy())->toBeTrue()
        ->and($report->storedBalance)->toBe(75)
        ->and($report->ledgerBalance)->toBe(75)
        ->and($report->lotsRemaining)->toBe(75);
});

it('passes after a reversal', function (): void {
    $grant = grantCredits($this->user, 100, CreditTransactionType::AdjustmentCredit);
    $this->ledger->reverse($grant, 'Posted in error.');

    expect($this->reconciler->reconcile(creditWalletFor($this->user)->fresh())->isHealthy())->toBeTrue();
});

// -------------------------------------------------------------- Corruption

it('detects a wallet balance that does not match the ledger', function (): void {
    grantCredits($this->user, 100);
    $wallet = creditWalletFor($this->user);

    // Bypasses every application guard, as a bad migration might.
    DB::statement('UPDATE credit_wallets SET balance = 500 WHERE id = ?', [$wallet->id]);

    $report = $this->reconciler->reconcile($wallet->fresh());

    expect($report->isHealthy())->toBeFalse()
        ->and($report->storedBalance)->toBe(500)
        ->and($report->ledgerBalance)->toBe(100)
        ->and(implode(' ', $report->problems))
        ->toContain('does not equal the sum of ledger amounts');
});

it('detects a lot allocation that does not match the balance', function (): void {
    grantCredits($this->user, 100);
    $wallet = creditWalletFor($this->user);

    DB::statement('UPDATE credit_lots SET remaining_amount = 40 WHERE credit_wallet_id = ?', [$wallet->id]);

    $report = $this->reconciler->reconcile($wallet->fresh());

    expect($report->isHealthy())->toBeFalse()
        ->and($report->lotsRemaining)->toBe(40)
        ->and(implode(' ', $report->problems))
        ->toContain('does not equal the wallet balance');
});

/*
 * Consumption rows cannot be altered -- a trigger forbids it -- so this drift
 * is produced the way it could actually occur: the lot's remaining amount
 * moving without a matching consumption record.
 */
it('detects a lot whose consumption records do not add up', function (): void {
    grantCredits($this->user, 100);
    $this->ledger->consumeCredits(creditWalletFor($this->user), 40);

    $lot = CreditLot::first();
    expect($lot->remaining_amount)->toBe(60);

    // Now 30 left, but the consumption records still only account for 40.
    DB::statement('UPDATE credit_lots SET remaining_amount = 30 WHERE id = ?', [$lot->id]);

    $report = $this->reconciler->reconcile(creditWalletFor($this->user)->fresh());

    expect($report->isHealthy())->toBeFalse()
        ->and(implode(' ', $report->problems))->toContain('consumed but its remaining amount implies');
});

it('detects a stored running balance that does not replay', function (): void {
    grantCredits($this->user, 100);
    grantCredits($this->user, 50);
    $wallet = creditWalletFor($this->user);

    // A ledger row claiming the wrong running balance. Triggers block an
    // UPDATE, so this is inserted as a fresh corrupt row.
    DB::statement(
        'INSERT INTO credit_transactions (credit_wallet_id, type, amount, balance_after, created_at)
         VALUES (?, ?, ?, ?, NOW())',
        [$wallet->id, 'purchase', 25, 999]
    );

    $report = $this->reconciler->reconcile($wallet->fresh());

    expect($report->isHealthy())->toBeFalse()
        ->and(implode(' ', $report->problems))->toContain('but the replayed balance is');
});

it('reports several problems at once rather than stopping at the first', function (): void {
    grantCredits($this->user, 100);
    $wallet = creditWalletFor($this->user);

    DB::statement('UPDATE credit_wallets SET balance = 500 WHERE id = ?', [$wallet->id]);
    DB::statement('UPDATE credit_lots SET remaining_amount = 10 WHERE credit_wallet_id = ?', [$wallet->id]);

    $report = $this->reconciler->reconcile($wallet->fresh());

    expect($report->problemCount())->toBeGreaterThan(1);
});

/*
 * A reconciler that quietly repaired what it found would destroy the evidence
 * needed to work out what caused the drift, and would let a real bug keep
 * producing wrong numbers while looking healthy.
 */
it('reports problems without repairing them', function (): void {
    grantCredits($this->user, 100);
    $wallet = creditWalletFor($this->user);

    DB::statement('UPDATE credit_wallets SET balance = 500 WHERE id = ?', [$wallet->id]);

    $this->reconciler->reconcile($wallet->fresh());

    // Still wrong afterwards. Fixing it is a human decision.
    expect($wallet->fresh()->balance)->toBe(500);
});

// ------------------------------------------------------------------- Sweep

it('finds the unhealthy wallets among many', function (): void {
    $healthy = User::factory()->create();
    grantCredits($healthy, 100);

    $broken = User::factory()->create();
    grantCredits($broken, 100);
    DB::statement('UPDATE credit_wallets SET balance = 7 WHERE id = ?', [creditWalletFor($broken)->id]);

    $reports = $this->reconciler->reconcileAll();

    expect($reports)->toHaveCount(1)
        ->and($reports->first()->walletId)->toBe(creditWalletFor($broken)->id);
});

it('returns nothing when every wallet is consistent', function (): void {
    grantCredits($this->user, 100);
    grantCredits(User::factory()->create(), 50);

    expect($this->reconciler->reconcileAll())->toBeEmpty();
});

it('summarises a report for logging without leaking anything sensitive', function (): void {
    grantCredits($this->user, 100);

    $array = $this->reconciler->reconcile(creditWalletFor($this->user)->fresh())->toArray();

    expect($array)->toHaveKeys(['wallet_id', 'stored_balance', 'ledger_balance', 'lots_remaining', 'healthy'])
        ->and($array['healthy'])->toBeTrue();
});

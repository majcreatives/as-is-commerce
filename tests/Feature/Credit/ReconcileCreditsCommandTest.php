<?php

declare(strict_types=1);

use App\Models\CreditTransaction;
use App\Models\CreditWallet;

/*
 * `credits:reconcile` from the command line.
 *
 * The exception centre is where an operator already looks, so the screen is
 * the primary surface. The command exists because the same question sometimes
 * has to be asked without somebody being logged in: on a schedule, during an
 * incident, or before a release.
 *
 * The test that matters most in here is the one that says the command changes
 * nothing. A reconciliation tool that quietly "corrected" the balance it was
 * asked to check would be choosing between two disagreeing records of the
 * truth, silently, on every run -- and it would also destroy the evidence
 * needed to work out what caused the drift. The ledger is authoritative and a
 * repair is a human decision made with a compensating entry.
 */

it('reports a drifted wallet and changes nothing', function (): void {
    $user = bidder();
    $wallet = CreditWallet::where('user_id', $user->id)->firstOrFail();
    $driftedTo = $wallet->balance + 777;

    CreditWallet::permittingBalanceWrites(function () use ($wallet, $driftedTo): void {
        $wallet->balance = $driftedTo;
        $wallet->save();
    });

    $transactions = CreditTransaction::where('credit_wallet_id', $wallet->id)->count();
    $lots = $wallet->lots()->count();

    $this->artisan('credits:reconcile')
        ->expectsOutputToContain('Credit Wallet #'.$wallet->id)
        // Both figures, because "they disagree" is not a diagnosis.
        ->expectsOutputToContain((string) $driftedTo)
        ->expectsOutputToContain('Nothing has been changed')
        ->assertExitCode(1);

    // The drift is still exactly the drift. Nothing was reconciled, because
    // reconciliation does not mean that.
    expect(CreditWallet::where('user_id', $user->id)->firstOrFail()->balance)->toBe($driftedTo)
        ->and(CreditTransaction::where('credit_wallet_id', $wallet->id)->count())->toBe($transactions)
        ->and($wallet->lots()->count())->toBe($lots);
});

it('passes when every wallet agrees with its ledger', function (): void {
    bidder();

    $this->artisan('credits:reconcile')
        ->expectsOutputToContain('agrees with its ledger')
        ->assertExitCode(0);
});

it('passes on an empty platform rather than reporting a false problem', function (): void {
    // No wallets at all is not a reconciliation failure, and a command that
    // said otherwise would train people to ignore it.
    $this->artisan('credits:reconcile')->assertExitCode(0);
});

it('states how many wallets it looked at, so a pass is not mistaken for a full sweep', function (): void {
    // A bounded sweep that says nothing about its bound invites the reader to
    // assume it checked everything. With more wallets than the ceiling, the
    // honest report is the bound, not a clean bill of health.
    $this->artisan('credits:reconcile')
        ->expectsOutputToContain('Reading at most 200 wallet(s), in id order.')
        ->assertExitCode(0);
});

it('accepts an explicit limit and reports it', function (): void {
    $this->artisan('credits:reconcile --limit=5')
        ->expectsOutputToContain('Reading at most 5 wallet(s)')
        ->assertExitCode(0);
});

it('never writes a credit transaction, whatever it finds', function (): void {
    $user = bidder();
    $wallet = CreditWallet::where('user_id', $user->id)->firstOrFail();

    CreditWallet::permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance = 31337;
        $wallet->save();
    });

    $before = CreditTransaction::where('credit_wallet_id', $wallet->id)
        ->orderBy('id')->pluck('amount', 'id')->all();

    $this->artisan('credits:reconcile')->assertExitCode(1);

    $after = CreditTransaction::where('credit_wallet_id', $wallet->id)
        ->orderBy('id')->pluck('amount', 'id')->all();

    expect($after)->toBe($before);
});

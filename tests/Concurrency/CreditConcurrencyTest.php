<?php

declare(strict_types=1);

use App\Domain\Credit\Exceptions\InsufficientCredits;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Models\CreditLot;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Concurrency, tested against real MySQL locks.
 *
 * These tests do not run inside RefreshDatabase's wrapping transaction --
 * they use truncation instead -- because a second connection cannot see data
 * an uncommitted transaction on the first connection has written. Wrapping
 * them would make the second connection observe an empty database and the
 * tests would pass for entirely the wrong reason.
 */

beforeEach(function (): void {
    seedRoles();

    $this->ledger = app(CreditLedgerService::class);
    $this->user = User::factory()->create();

    // A second, genuinely independent connection to the same schema.
    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');
});

afterEach(function (): void {
    // Leave no transaction open if an assertion failed mid-test.
    try {
        while (DB::connection('second')->transactionLevel() > 0) {
            DB::connection('second')->rollBack();
        }
    } catch (Throwable) {
        // Connection already gone; nothing to unwind.
    }

    DB::purge('second');
});

/*
 * The primitive everything else rests on. If the wallet row lock does not
 * actually serialize access, no amount of application logic prevents two
 * simultaneous debits from reading the same starting balance.
 */
it('serializes access to a wallet row across connections', function (): void {
    grantCredits($this->user, 10);
    $walletId = creditWalletFor($this->user)->id;

    // Connection one takes the lock and holds it.
    DB::beginTransaction();
    DB::table('credit_wallets')->where('id', $walletId)->lockForUpdate()->first();

    // Connection two gives up quickly rather than waiting the default 50s.
    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')
            ->table('credit_wallets')
            ->where('id', $walletId)
            ->lockForUpdate()
            ->first();
    } catch (QueryException $e) {
        // 1205: lock wait timeout. The second connection was made to wait,
        // which is exactly the behaviour that prevents overspending.
        $blocked = str_contains($e->getMessage(), '1205')
            || str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second connection must not acquire the wallet lock while it is held.');
});

/*
 * The overspend scenario from the specification: a wallet holds 10, and two
 * operations each try to take 10. Exactly one may succeed.
 */
it('does not let two debits spend the same credits', function (): void {
    grantCredits($this->user, 10);

    $succeeded = 0;
    $refused = 0;

    foreach (range(1, 2) as $ignored) {
        try {
            // Each attempt is handed a separately loaded wallet, as two
            // independent requests would be.
            $this->ledger->consumeCredits(CreditWallet::findOrFail(creditWalletFor($this->user)->id), 10);
            $succeeded++;
        } catch (InsufficientCredits) {
            $refused++;
        }
    }

    expect($succeeded)->toBe(1)
        ->and($refused)->toBe(1)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(0)
        ->and((int) CreditLot::sum('remaining_amount'))->toBe(0);
});

/*
 * The specific failure a lock protects against: acting on a balance that was
 * true when the request started but is not true any more.
 */
it('ignores a stale balance on the model it was handed', function (): void {
    grantCredits($this->user, 100);

    // A wallet object loaded when the balance was 100.
    $stale = CreditWallet::findOrFail(creditWalletFor($this->user)->id);
    expect($stale->balance)->toBe(100);

    // Meanwhile the credits are spent elsewhere.
    $this->ledger->consumeCredits(creditWalletFor($this->user), 95);

    // The stale object still claims 100, but the service re-reads under lock.
    expect($stale->balance)->toBe(100);

    expect(fn (): CreditTransaction => $this->ledger->consumeCredits($stale, 50))
        ->toThrow(InsufficientCredits::class);

    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(5);
});

it('leaves nothing behind when a debit is refused', function (): void {
    grantCredits($this->user, 10);

    $transactionsBefore = CreditTransaction::count();
    $consumptionsBefore = DB::table('credit_lot_consumptions')->count();

    try {
        $this->ledger->consumeCredits(creditWalletFor($this->user), 50);
    } catch (InsufficientCredits) {
        // expected
    }

    expect(CreditTransaction::count())->toBe($transactionsBefore)
        ->and(DB::table('credit_lot_consumptions')->count())->toBe($consumptionsBefore)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(10)
        ->and((int) CreditLot::sum('remaining_amount'))->toBe(10);
});

it('keeps the ledger consistent through a long run of debits', function (): void {
    grantCredits($this->user, 100);

    $spent = 0;

    foreach (range(1, 100) as $ignored) {
        try {
            $this->ledger->consumeCredits(creditWalletFor($this->user), 1);
            $spent++;
        } catch (InsufficientCredits) {
            break;
        }
    }

    // Exactly the granted amount was spendable, no more.
    expect($spent)->toBe(100)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(0)
        ->and((int) CreditTransaction::sum('amount'))->toBe(0)
        ->and((int) CreditLot::sum('remaining_amount'))->toBe(0);
});

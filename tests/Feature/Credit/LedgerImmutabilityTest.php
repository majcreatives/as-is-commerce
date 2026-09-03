<?php

declare(strict_types=1);

use App\Domain\Credit\Exceptions\InvalidLedgerOperation;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Shared\Ledger\FinancialHistoryIsImmutable;
use App\Enums\CreditTransactionType;
use App\Models\CreditLot;
use App\Models\CreditLotConsumption;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedRoles();
    $this->ledger = app(CreditLedgerService::class);
    $this->user = User::factory()->create();
});

// ------------------------------------------------------ Application guards

it('refuses to edit a credit transaction', function (): void {
    $transaction = grantCredits($this->user, 100);

    $transaction->amount = 50;

    expect(fn () => $transaction->save())->toThrow(FinancialHistoryIsImmutable::class);
    expect($transaction->fresh()->amount)->toBe(100);
});

it('refuses to delete a credit transaction', function (): void {
    $transaction = grantCredits($this->user, 100);

    expect(fn () => $transaction->delete())->toThrow(FinancialHistoryIsImmutable::class);
    expect(CreditTransaction::count())->toBe(1);
});

it('refuses to edit a lot consumption record', function (): void {
    grantCredits($this->user, 100);
    $this->ledger->consumeCredits(creditWalletFor($this->user), 10);

    $consumption = CreditLotConsumption::first();
    $consumption->amount = 999;

    expect(fn () => $consumption->save())->toThrow(FinancialHistoryIsImmutable::class);
});

it('refuses to delete a lot consumption record', function (): void {
    grantCredits($this->user, 100);
    $this->ledger->consumeCredits(creditWalletFor($this->user), 10);

    expect(fn () => CreditLotConsumption::first()->delete())
        ->toThrow(FinancialHistoryIsImmutable::class);
});

// ---------------------------------------------------------- Database guards

/*
 * The application guards are a courtesy; these are the real ones. They hold
 * even for a raw query that bypasses Eloquent entirely, which is what a
 * console command, a bad migration or a hand-run SQL session would do.
 */

it('blocks a raw update of a credit transaction', function (): void {
    $transaction = grantCredits($this->user, 100);

    expect(fn () => DB::statement(
        'UPDATE credit_transactions SET amount = 999 WHERE id = ?', [$transaction->id]
    ))->toThrow(QueryException::class);

    expect($transaction->fresh()->amount)->toBe(100);
});

it('blocks a raw delete of a credit transaction', function (): void {
    $transaction = grantCredits($this->user, 100);

    expect(fn () => DB::statement('DELETE FROM credit_transactions WHERE id = ?', [$transaction->id]))
        ->toThrow(QueryException::class);

    expect(CreditTransaction::count())->toBe(1);
});

it('blocks a raw update of a consumption record', function (): void {
    grantCredits($this->user, 100);
    $debit = $this->ledger->consumeCredits(creditWalletFor($this->user), 10);

    $consumptionId = CreditLotConsumption::where('credit_transaction_id', $debit->id)->value('id');

    expect(fn () => DB::statement(
        'UPDATE credit_lot_consumptions SET amount = 999 WHERE id = ?', [$consumptionId]
    ))->toThrow(QueryException::class);
});

it('rejects a transaction with a zero amount', function (): void {
    $wallet = creditWalletFor($this->user);

    expect(fn () => CreditTransaction::create([
        'credit_wallet_id' => $wallet->id,
        'type' => CreditTransactionType::Purchase,
        'amount' => 0,
        'balance_after' => 0,
    ]))->toThrow(QueryException::class);
});

it('rejects a negative balance_after', function (): void {
    $wallet = creditWalletFor($this->user);

    expect(fn () => CreditTransaction::create([
        'credit_wallet_id' => $wallet->id,
        'type' => CreditTransactionType::BidDebit,
        'amount' => -5,
        'balance_after' => -5,
    ]))->toThrow(QueryException::class);
});

it('rejects a negative remaining amount on a lot', function (): void {
    $transaction = grantCredits($this->user, 100);
    $lot = $transaction->lots()->first();

    expect(fn () => DB::statement(
        'UPDATE credit_lots SET remaining_amount = -1 WHERE id = ?', [$lot->id]
    ))->toThrow(QueryException::class);
});

it('rejects a lot holding more than it originally did', function (): void {
    $transaction = grantCredits($this->user, 100);
    $lot = $transaction->lots()->first();

    expect(fn () => DB::statement(
        'UPDATE credit_lots SET remaining_amount = 500 WHERE id = ?', [$lot->id]
    ))->toThrow(QueryException::class);
});

it('rejects a lot with a zero original amount', function (): void {
    expect(fn () => CreditLot::factory()->create(['original_amount' => 0, 'remaining_amount' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects a zero-amount consumption', function (): void {
    expect(fn () => CreditLotConsumption::factory()->create(['amount' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects a second wallet for the same user', function (): void {
    expect(fn () => CreditWallet::create(['user_id' => $this->user->id]))
        ->toThrow(QueryException::class);
});

it('rejects a transaction pointing at a wallet that does not exist', function (): void {
    expect(fn () => CreditTransaction::create([
        'credit_wallet_id' => 999_999,
        'type' => CreditTransactionType::Purchase,
        'amount' => 10,
        'balance_after' => 10,
    ]))->toThrow(QueryException::class);
});

// ----------------------------------------------------- Compensating entries

/*
 * The sanctioned way to undo something: post the opposite, leaving both the
 * mistake and the correction on the record.
 */
it('undoes a mistaken grant with a reversal rather than an edit', function (): void {
    $mistake = grantCredits($this->user, 100, CreditTransactionType::AdjustmentCredit);

    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(100);

    $reversal = $this->ledger->reverse($mistake, 'Posted in error.');

    expect($reversal->type)->toBe(CreditTransactionType::Reversal)
        ->and($reversal->amount)->toBe(-100)
        ->and($reversal->balance_after)->toBe(0)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(0)
        // Both rows survive: the history is intact.
        ->and(CreditTransaction::count())->toBe(2)
        ->and($mistake->fresh()->amount)->toBe(100);
});

it('reverses the lot as well as the balance', function (): void {
    $mistake = grantCredits($this->user, 100, CreditTransactionType::AdjustmentCredit);

    $this->ledger->reverse($mistake, 'Posted in error.');

    expect($mistake->lots()->first()->fresh()->remaining_amount)->toBe(0);
});

it('supports the correction sequence of a wrong amount', function (): void {
    // +100 posted by mistake, should have been +50.
    $wrong = grantCredits($this->user, 100, CreditTransactionType::AdjustmentCredit);
    $this->ledger->reverse($wrong, 'Wrong amount.');
    grantCredits($this->user, 50, CreditTransactionType::AdjustmentCredit);

    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(50)
        ->and(CreditTransaction::count())->toBe(3);
});

/*
 * Credits already spent cannot be clawed back from a lot that no longer holds
 * them. Failing loudly is right: recovering spent credits is a business
 * decision, not something to infer.
 */
it('refuses to reverse credits that have already been spent', function (): void {
    $grant = grantCredits($this->user, 100, CreditTransactionType::AdjustmentCredit);
    $this->ledger->consumeCredits(creditWalletFor($this->user), 30);

    expect(fn (): CreditTransaction => $this->ledger->reverse($grant, 'Too late.'))
        ->toThrow(InvalidLedgerOperation::class);

    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(70);
});

it('refuses to reverse a debit with this method', function (): void {
    grantCredits($this->user, 100);
    $debit = $this->ledger->consumeCredits(creditWalletFor($this->user), 10);

    expect(fn (): CreditTransaction => $this->ledger->reverse($debit, 'No.'))
        ->toThrow(InvalidLedgerOperation::class);
});

<?php

declare(strict_types=1);

use App\Domain\Cash\Exceptions\InsufficientFunds;
use App\Domain\Cash\Services\CashLedgerService;
use App\Domain\Credit\Exceptions\InvalidLedgerOperation;
use App\Domain\Shared\Ledger\BalanceMutationForbidden;
use App\Domain\Shared\Ledger\FinancialHistoryIsImmutable;
use App\Domain\Shared\Money\Money;
use App\Enums\CashTransactionType;
use App\Models\CashTransaction;
use App\Models\CashWallet;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedRoles();

    $this->cash = app(CashLedgerService::class);
    $this->user = User::factory()->create();
    $this->wallet = $this->cash->walletFor($this->user);
});

// ------------------------------------------------------------ Provisioning

it('gives every new user a cash wallet starting at zero', function (): void {
    expect($this->wallet->balance_minor)->toBe(0)
        ->and($this->wallet->balance()->format())->toBe('0.00')
        ->and($this->wallet->currency)->toBe('GHS');
});

it('gives a user exactly one cash wallet', function (): void {
    expect($this->cash->walletFor($this->user)->id)->toBe($this->wallet->id);
});

it('rejects a second cash wallet for the same user', function (): void {
    expect(fn () => CashWallet::create(['user_id' => $this->user->id]))
        ->toThrow(QueryException::class);
});

// ------------------------------------------------------------------- Money

/*
 * The requirement from the specification: GH 100.00 is stored as 10000, and
 * no float is involved at any point.
 */
it('stores money as integer minor units', function (): void {
    $transaction = $this->cash->credit(
        $this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('100.00')
    );

    expect($transaction->amount_minor)->toBe(10_000)->toBeInt()
        ->and($transaction->balance_after_minor)->toBe(10_000)
        ->and($this->wallet->fresh()->balance_minor)->toBe(10_000);

    // Straight from the database, so no cast could be hiding a float.
    $raw = DB::table('cash_transactions')->where('id', $transaction->id)->first();
    expect($raw->amount_minor)->toEqual(10_000);
});

it('keeps sub-unit amounts exact', function (string $decimal, int $minor): void {
    $transaction = $this->cash->credit(
        $this->wallet, CashTransactionType::Deposit, Money::fromDecimalString($decimal)
    );

    expect($transaction->amount_minor)->toBe($minor)
        ->and($transaction->amount()->toDecimalString())->toBe(
            number_format((float) $decimal, 2, '.', '')
        );
})->with([
    'the classic float trap' => ['0.29', 29],
    'one pesewa' => ['0.01', 1],
    'large amount' => ['5500.50', 550_050],
]);

it('does not drift across many small transactions', function (): void {
    foreach (range(1, 100) as $ignored) {
        $this->cash->credit($this->wallet->fresh(), CashTransactionType::Deposit, Money::fromDecimalString('0.29'));
    }

    // A float accumulation would not land exactly on 29.00.
    expect($this->wallet->fresh()->balance_minor)->toBe(2_900)
        ->and($this->wallet->fresh()->balance()->toDecimalString())->toBe('29.00');
});

// ------------------------------------------------------------- Credit/debit

it('records a deposit and raises the balance', function (): void {
    $transaction = $this->cash->credit(
        $this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('250.00')
    );

    expect($transaction->type)->toBe(CashTransactionType::Deposit)
        ->and($transaction->amount_minor)->toBe(25_000)
        ->and($this->wallet->fresh()->balance_minor)->toBe(25_000);
});

it('records a debit and lowers the balance', function (): void {
    $this->cash->credit($this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('100.00'));

    $transaction = $this->cash->debit(
        $this->wallet->fresh(), CashTransactionType::CreditPurchase, Money::fromDecimalString('40.00')
    );

    expect($transaction->amount_minor)->toBe(-4_000)
        ->and($transaction->balance_after_minor)->toBe(6_000)
        ->and($this->wallet->fresh()->balance_minor)->toBe(6_000);
});

it('refuses a debit that would overdraw the account', function (): void {
    $this->cash->credit($this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('10.00'));

    expect(fn (): CashTransaction => $this->cash->debit(
        $this->wallet->fresh(), CashTransactionType::Withdrawal, Money::fromDecimalString('10.01')
    ))->toThrow(InsufficientFunds::class);

    expect($this->wallet->fresh()->balance_minor)->toBe(1_000);
});

it('never lets a cash balance go negative', function (): void {
    expect(fn (): CashTransaction => $this->cash->debit(
        $this->wallet, CashTransactionType::Withdrawal, Money::fromDecimalString('1.00')
    ))->toThrow(InsufficientFunds::class);

    expect($this->wallet->fresh()->balance_minor)->toBe(0);
});

it('refuses a non-positive amount', function (): void {
    expect(fn (): CashTransaction => $this->cash->credit(
        $this->wallet, CashTransactionType::Deposit, Money::zero()
    ))->toThrow(InvalidLedgerOperation::class);
});

// ------------------------------------------------------------ Immutability

it('refuses to edit a cash transaction', function (): void {
    $transaction = $this->cash->credit(
        $this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('50.00')
    );

    $transaction->amount_minor = 999;

    expect(fn () => $transaction->save())->toThrow(FinancialHistoryIsImmutable::class);
});

it('blocks a raw update of a cash transaction', function (): void {
    $transaction = $this->cash->credit(
        $this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('50.00')
    );

    expect(fn () => DB::statement(
        'UPDATE cash_transactions SET amount_minor = 1 WHERE id = ?', [$transaction->id]
    ))->toThrow(QueryException::class);
});

it('refuses a direct write to the cash balance', function (): void {
    $wallet = $this->wallet;
    $wallet->balance_minor = 999_999;

    expect(fn () => $wallet->save())->toThrow(BalanceMutationForbidden::class);
});

it('undoes a transaction with a compensating entry', function (): void {
    $deposit = $this->cash->credit(
        $this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('100.00')
    );

    $reversal = $this->cash->reverse($deposit, 'Payment charged back.');

    expect($reversal->type)->toBe(CashTransactionType::Reversal)
        ->and($reversal->amount_minor)->toBe(-10_000)
        ->and($this->wallet->fresh()->balance_minor)->toBe(0)
        ->and(CashTransaction::count())->toBe(2)
        ->and($deposit->fresh()->amount_minor)->toBe(10_000);
});

// ------------------------------------------------------------- Separation

/*
 * Cash and credits are different things. A cash movement must never touch the
 * credit ledger, and vice versa -- that separation is what stops a credit
 * refund from being settled as a cash liability.
 */
it('keeps the cash ledger entirely separate from the credit ledger', function (): void {
    $this->cash->credit($this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('100.00'));

    expect(CashTransaction::count())->toBe(1)
        ->and(CreditTransaction::count())->toBe(0)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(0);
});

it('leaves the cash balance untouched when credits move', function (): void {
    $this->cash->credit($this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('100.00'));

    grantCredits($this->user, 500);

    expect($this->wallet->fresh()->balance_minor)->toBe(10_000)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(500)
        ->and(CashTransaction::count())->toBe(1)
        ->and(CreditTransaction::count())->toBe(1);
});

/*
 * A credit purchase will eventually be exactly this: money out of the cash
 * ledger, credits into the credit ledger, as two entries in two ledgers. The
 * payment workflow belongs to a later stage; this only shows the accounting
 * can represent it.
 */
it('can represent a credit purchase as two entries in two ledgers', function (): void {
    $this->cash->credit($this->wallet, CashTransactionType::Deposit, Money::fromDecimalString('100.00'));

    $cashSide = $this->cash->debit(
        $this->wallet->fresh(),
        CashTransactionType::CreditPurchase,
        Money::fromDecimalString('100.00'),
        description: 'Purchase of 100 bidding credits.',
    );

    $creditSide = grantCredits($this->user, 100);

    expect($cashSide->amount_minor)->toBe(-10_000)
        ->and($this->wallet->fresh()->balance_minor)->toBe(0)
        ->and($creditSide->amount)->toBe(100)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(100);
});

it('rejects a zero-amount cash transaction at the database level', function (): void {
    expect(fn () => CashTransaction::factory()->create(['amount_minor' => 0]))
        ->toThrow(QueryException::class);
});

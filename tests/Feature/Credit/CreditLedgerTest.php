<?php

declare(strict_types=1);

use App\Domain\Credit\Exceptions\InsufficientCredits;
use App\Domain\Credit\Exceptions\InvalidLedgerOperation;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Shared\Ledger\BalanceMutationForbidden;
use App\Enums\CreditLotSource;
use App\Enums\CreditTransactionType;
use App\Models\CreditLot;
use App\Models\CreditLotConsumption;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

beforeEach(function (): void {
    seedRoles();
    $this->ledger = app(CreditLedgerService::class);
    $this->user = User::factory()->create();
});

// -------------------------------------------------------------- Provisioning

it('gives every new user a credit wallet starting at zero', function (): void {
    $wallet = creditWalletFor($this->user);

    expect($wallet->exists)->toBeTrue()
        ->and($wallet->balance)->toBe(0)
        ->and($wallet->spendableBalance())->toBe(0);
});

it('gives a user exactly one credit wallet', function (): void {
    $first = $this->ledger->walletFor($this->user);
    $second = $this->ledger->walletFor($this->user);

    expect($second->id)->toBe($first->id);
});

// ------------------------------------------------------------------ Adding

it('records a purchase and raises the balance', function (): void {
    $transaction = grantCredits($this->user, 100);

    expect($transaction->amount)->toBe(100)
        ->and($transaction->balance_after)->toBe(100)
        ->and($transaction->type)->toBe(CreditTransactionType::Purchase)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(100);
});

it('creates a lot carrying the source of the credits', function (CreditTransactionType $type, CreditLotSource $source): void {
    grantCredits($this->user, 50, $type);

    $lot = CreditLot::first();

    expect($lot->source_type)->toBe($source)
        ->and($lot->original_amount)->toBe(50)
        ->and($lot->remaining_amount)->toBe(50);
})->with([
    'purchase' => [CreditTransactionType::Purchase, CreditLotSource::Purchased],
    'promotional' => [CreditTransactionType::PromotionalCredit, CreditLotSource::Promotional],
    'referral' => [CreditTransactionType::ReferralCredit, CreditLotSource::Referral],
    'adjustment' => [CreditTransactionType::AdjustmentCredit, CreditLotSource::Adjustment],
]);

it('accumulates the balance across several grants', function (): void {
    grantCredits($this->user, 100);
    grantCredits($this->user, 50, CreditTransactionType::PromotionalCredit);
    $third = grantCredits($this->user, 25, CreditTransactionType::ReferralCredit);

    expect($third->balance_after)->toBe(175)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(175)
        ->and(CreditLot::count())->toBe(3);
});

it('refuses to add a non-positive amount', function (int $amount): void {
    expect(fn (): CreditTransaction => $this->ledger->addCredits(
        wallet: creditWalletFor($this->user),
        type: CreditTransactionType::Purchase,
        amount: $amount,
    ))->toThrow(InvalidLedgerOperation::class);
})->with([0, -1, -100]);

it('refuses to add credits using a debit type', function (): void {
    expect(fn (): CreditTransaction => $this->ledger->addCredits(
        wallet: creditWalletFor($this->user),
        type: CreditTransactionType::BidDebit,
        amount: 10,
    ))->toThrow(InvalidLedgerOperation::class);
});

// --------------------------------------------------------------- Consuming

it('consumes credits and lowers the balance', function (): void {
    grantCredits($this->user, 100);

    $debit = $this->ledger->consumeCredits(creditWalletFor($this->user), 30);

    expect($debit->amount)->toBe(-30)
        ->and($debit->balance_after)->toBe(70)
        ->and($debit->type)->toBe(CreditTransactionType::BidDebit)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(70);
});

it('records which lot the credits came from', function (): void {
    grantCredits($this->user, 100);
    $lot = CreditLot::first();

    $debit = $this->ledger->consumeCredits(creditWalletFor($this->user), 30);

    $consumption = CreditLotConsumption::where('credit_transaction_id', $debit->id)->first();

    expect($consumption)->not->toBeNull()
        ->and($consumption->credit_lot_id)->toBe($lot->id)
        ->and($consumption->amount)->toBe(30)
        ->and($lot->fresh()->remaining_amount)->toBe(70);
});

it('marks a lot exhausted when it is fully drawn down', function (): void {
    grantCredits($this->user, 40);

    $this->ledger->consumeCredits(creditWalletFor($this->user), 40);

    $lot = CreditLot::first();

    expect($lot->remaining_amount)->toBe(0)
        ->and($lot->exhausted_at)->not->toBeNull()
        ->and($lot->isSpendable())->toBeFalse();
});

it('refuses to spend more than the wallet holds', function (): void {
    grantCredits($this->user, 10);

    expect(fn (): CreditTransaction => $this->ledger->consumeCredits(creditWalletFor($this->user), 11))
        ->toThrow(InsufficientCredits::class);

    // Nothing partial was written.
    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(10)
        ->and(CreditLot::first()->remaining_amount)->toBe(10)
        ->and(CreditLotConsumption::count())->toBe(0)
        ->and(CreditTransaction::count())->toBe(1);
});

it('refuses to spend from an empty wallet', function (): void {
    expect(fn (): CreditTransaction => $this->ledger->consumeCredits(creditWalletFor($this->user), 1))
        ->toThrow(InsufficientCredits::class);
});

it('refuses to consume a non-positive amount', function (int $amount): void {
    grantCredits($this->user, 100);

    expect(fn (): CreditTransaction => $this->ledger->consumeCredits(creditWalletFor($this->user), $amount))
        ->toThrow(InvalidLedgerOperation::class);
})->with([0, -1]);

// ---------------------------------------------------------------- Balances

it('never lets a balance go negative', function (): void {
    grantCredits($this->user, 5);

    try {
        $this->ledger->consumeCredits(creditWalletFor($this->user), 10);
    } catch (InsufficientCredits) {
        // expected
    }

    expect(creditWalletFor($this->user)->fresh()->balance)->toBeGreaterThanOrEqual(0);
});

/*
 * The balance column is a projection of the ledger. Letting application code
 * write it would create a second source of truth that silently drifts.
 */
it('refuses a direct write to the wallet balance', function (): void {
    $wallet = creditWalletFor($this->user);
    $wallet->balance = 999_999;

    expect(fn () => $wallet->save())->toThrow(BalanceMutationForbidden::class);

    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(0);
});

/*
 * Strict mode turns a silently discarded attribute into a loud failure, which
 * is what we want: an attempt to mass-assign a balance is a bug, not
 * something to ignore.
 */
it('refuses to mass-assign a balance', function (): void {
    $wallet = creditWalletFor($this->user);

    expect(fn () => $wallet->fill(['balance' => 500]))
        ->toThrow(MassAssignmentException::class);

    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(0);
});

// --------------------------------------------------------------- Invariants

/*
 * After any sequence of operations the three representations of the balance
 * must agree. Randomised amounts, so this is not just the cases I thought of.
 */
it('keeps balance, ledger and lots in agreement across many operations', function (): void {
    $wallet = creditWalletFor($this->user);
    $expected = 0;

    foreach (range(1, 25) as $i) {
        if ($i % 3 === 0 && $expected > 0) {
            $spend = random_int(1, $expected);
            $this->ledger->consumeCredits($wallet->fresh(), $spend);
            $expected -= $spend;
        } else {
            $add = random_int(1, 500);
            grantCredits($this->user, $add);
            $expected += $add;
        }

        $fresh = $wallet->fresh();
        $ledgerSum = (int) CreditTransaction::where('credit_wallet_id', $wallet->id)->sum('amount');
        $lotsRemaining = (int) CreditLot::where('credit_wallet_id', $wallet->id)->sum('remaining_amount');

        expect($fresh->balance)->toBe($expected)
            ->and($ledgerSum)->toBe($expected)
            ->and($lotsRemaining)->toBe($expected)
            ->and($fresh->balance)->toBeGreaterThanOrEqual(0);
    }
});

it('records a running balance that replays correctly', function (): void {
    grantCredits($this->user, 100);
    $this->ledger->consumeCredits(creditWalletFor($this->user), 30);
    grantCredits($this->user, 10, CreditTransactionType::PromotionalCredit);
    $this->ledger->consumeCredits(creditWalletFor($this->user), 5);

    $running = 0;

    foreach (CreditTransaction::orderBy('id')->get() as $transaction) {
        $running += $transaction->amount;
        expect($transaction->balance_after)->toBe($running);
    }

    expect($running)->toBe(75);
});

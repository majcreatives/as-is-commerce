<?php

declare(strict_types=1);

use App\Domain\Credit\Exceptions\InsufficientCredits;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Enums\CreditTransactionType;
use App\Models\CreditLot;
use App\Models\CreditLotConsumption;
use App\Models\CreditTransaction;
use App\Models\User;

/*
 * Which credits get spent, and in what order.
 *
 * The order is a promise to the customer: promotional credits -- the ones
 * that can be lost by expiring -- are spent before purchased credits, which
 * they actually paid for. Getting this wrong destroys value that belongs to
 * the user, silently.
 */

beforeEach(function (): void {
    seedRoles();
    $this->ledger = app(CreditLedgerService::class);
    $this->user = User::factory()->create();
});

/**
 * @return array<string, int> lot label => remaining amount
 */
function remainingByLabel(array $lots): array
{
    return collect($lots)
        ->mapWithKeys(fn (CreditLot $lot, string $label): array => [
            $label => $lot->fresh()->remaining_amount,
        ])
        ->all();
}

it('spends promotional credits before purchased ones', function (): void {
    $purchased = grantCredits($this->user, 100, CreditTransactionType::Purchase);
    $promotional = grantCredits($this->user, 20, CreditTransactionType::PromotionalCredit);

    $this->ledger->consumeCredits(creditWalletFor($this->user), 5);

    $lots = remainingByLabel([
        'purchased' => $purchased->lots()->first(),
        'promotional' => $promotional->lots()->first(),
    ]);

    expect($lots['promotional'])->toBe(15)
        ->and($lots['purchased'])->toBe(100);
});

it('spends the soonest-expiring lot first within a source', function (): void {
    $later = grantCredits($this->user, 10, CreditTransactionType::PromotionalCredit, now()->addMonths(2));
    $sooner = grantCredits($this->user, 10, CreditTransactionType::PromotionalCredit, now()->addMonth());

    $this->ledger->consumeCredits(creditWalletFor($this->user), 4);

    $lots = remainingByLabel([
        'later' => $later->lots()->first(),
        'sooner' => $sooner->lots()->first(),
    ]);

    // The lot created second is spent first, because it expires first.
    expect($lots['sooner'])->toBe(6)
        ->and($lots['later'])->toBe(10);
});

it('spends expiring credits before credits that never expire', function (): void {
    $never = grantCredits($this->user, 10, CreditTransactionType::PromotionalCredit);
    $expiring = grantCredits($this->user, 10, CreditTransactionType::PromotionalCredit, now()->addWeek());

    $this->ledger->consumeCredits(creditWalletFor($this->user), 6);

    $lots = remainingByLabel([
        'never' => $never->lots()->first(),
        'expiring' => $expiring->lots()->first(),
    ]);

    expect($lots['expiring'])->toBe(4)
        ->and($lots['never'])->toBe(10);
});

it('spends the oldest lot first when expiry is identical', function (): void {
    $expiry = now()->addMonth();

    $older = grantCredits($this->user, 10, CreditTransactionType::PromotionalCredit, $expiry);
    $newer = grantCredits($this->user, 10, CreditTransactionType::PromotionalCredit, $expiry);

    $this->ledger->consumeCredits(creditWalletFor($this->user), 4);

    $lots = remainingByLabel([
        'older' => $older->lots()->first(),
        'newer' => $newer->lots()->first(),
    ]);

    expect($lots['older'])->toBe(6)
        ->and($lots['newer'])->toBe(10);
});

it('draws from several lots when one cannot cover the amount', function (): void {
    $promotional = grantCredits($this->user, 20, CreditTransactionType::PromotionalCredit);
    $purchased = grantCredits($this->user, 100, CreditTransactionType::Purchase);

    $debit = $this->ledger->consumeCredits(creditWalletFor($this->user), 50);

    $lots = remainingByLabel([
        'promotional' => $promotional->lots()->first(),
        'purchased' => $purchased->lots()->first(),
    ]);

    // Promotional exhausted first, then the remainder from purchased.
    expect($lots['promotional'])->toBe(0)
        ->and($lots['purchased'])->toBe(70);

    $consumptions = CreditLotConsumption::where('credit_transaction_id', $debit->id)
        ->orderBy('id')->get();

    expect($consumptions)->toHaveCount(2)
        ->and($consumptions[0]->amount)->toBe(20)
        ->and($consumptions[1]->amount)->toBe(30)
        // Every credit spent is accounted to a lot: the parts sum to the whole.
        ->and($consumptions->sum('amount'))->toBe(50);
});

it('follows the full source order across four lots', function (): void {
    $purchased = grantCredits($this->user, 10, CreditTransactionType::Purchase);
    $adjustment = grantCredits($this->user, 10, CreditTransactionType::AdjustmentCredit);
    $referral = grantCredits($this->user, 10, CreditTransactionType::ReferralCredit);
    $promotional = grantCredits($this->user, 10, CreditTransactionType::PromotionalCredit);

    // Take 25: promotional (10), referral (10), then 5 of adjustment.
    $this->ledger->consumeCredits(creditWalletFor($this->user), 25);

    $lots = remainingByLabel([
        'promotional' => $promotional->lots()->first(),
        'referral' => $referral->lots()->first(),
        'adjustment' => $adjustment->lots()->first(),
        'purchased' => $purchased->lots()->first(),
    ]);

    expect($lots)->toBe([
        'promotional' => 0,
        'referral' => 0,
        'adjustment' => 5,
        'purchased' => 10,
    ]);
});

// ------------------------------------------------------------------ Expiry

it('will not spend credits from an expired lot', function (): void {
    grantCredits($this->user, 100, CreditTransactionType::PromotionalCredit, now()->subDay());

    expect(fn (): CreditTransaction => $this->ledger->consumeCredits(creditWalletFor($this->user), 1))
        ->toThrow(InsufficientCredits::class);
});

it('reports expired credits as unspendable while they still count in the ledger', function (): void {
    grantCredits($this->user, 60, CreditTransactionType::PromotionalCredit, now()->subDay());
    grantCredits($this->user, 40, CreditTransactionType::Purchase);

    $wallet = creditWalletFor($this->user)->fresh();

    // The ledger still holds all 100 until an expiry transaction is posted,
    // but only 40 can actually be spent.
    expect($wallet->balance)->toBe(100)
        ->and($wallet->spendableBalance())->toBe(40)
        ->and($wallet->expiredUnclaimedBalance())->toBe(60);
});

it('skips expired lots and spends the valid ones', function (): void {
    $expired = grantCredits($this->user, 50, CreditTransactionType::PromotionalCredit, now()->subDay());
    $valid = grantCredits($this->user, 50, CreditTransactionType::Purchase);

    $this->ledger->consumeCredits(creditWalletFor($this->user), 20);

    expect($expired->lots()->first()->fresh()->remaining_amount)->toBe(50)
        ->and($valid->lots()->first()->fresh()->remaining_amount)->toBe(30);
});

it('writes off expired credits with an expiry transaction', function (): void {
    grantCredits($this->user, 60, CreditTransactionType::PromotionalCredit, now()->subDay());
    grantCredits($this->user, 40, CreditTransactionType::Purchase);

    $transaction = $this->ledger->expireLots(creditWalletFor($this->user));

    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe(CreditTransactionType::Expiration)
        ->and($transaction->amount)->toBe(-60)
        ->and($transaction->balance_after)->toBe(40)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(40);
});

/*
 * The expiry worker belongs to a later stage and may well run more than once.
 * Running it twice must not charge the customer twice.
 */
it('is safe to run expiry twice', function (): void {
    grantCredits($this->user, 60, CreditTransactionType::PromotionalCredit, now()->subDay());

    $this->ledger->expireLots(creditWalletFor($this->user));
    $second = $this->ledger->expireLots(creditWalletFor($this->user));

    expect($second)->toBeNull()
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(0)
        ->and(CreditTransaction::where('type', CreditTransactionType::Expiration)->count())->toBe(1);
});

it('does nothing when no credits have expired', function (): void {
    grantCredits($this->user, 100);

    expect($this->ledger->expireLots(creditWalletFor($this->user)))->toBeNull()
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(100);
});

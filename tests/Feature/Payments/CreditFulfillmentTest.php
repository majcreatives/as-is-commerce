<?php

declare(strict_types=1);

use App\Domain\Credit\Services\CreditAllocationService;
use App\Domain\Credit\Services\CreditLedgerReconciler;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Payments\Actions\FulfillCreditPurchase;
use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Enums\CashTransactionType;
use App\Enums\CreditLotSource;
use App\Enums\CreditPurchaseStatus;
use App\Enums\CreditTransactionType;
use App\Models\CashTransaction;
use App\Models\CreditLot;
use App\Models\CreditPurchase;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    seedRoles();
    config(['paystack.secret_key' => 'sk_test_stage_four']);

    $this->customer = User::factory()->create();

    $this->purchase = CreditPurchase::factory()->awaitingPayment()->create([
        'user_id' => $this->customer->id,
        'package_name_snapshot' => 'Popular',
        'credit_amount' => 500,
        'amount_minor' => 4_500,
        'currency' => 'GHS',
        'provider_reference' => 'AIC-FULFIL-001',
    ]);

    fakeVerify();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeVerify(array $overrides = []): void
{
    // Resets the stubs rather than appending, so an override inside a test
    // genuinely replaces the success stub set up in beforeEach. Appending
    // would leave the success stub matching first, and the test would pass
    // while proving nothing.
    fakePaystackVerify(array_replace([
        'id' => 987654321,
        'reference' => 'AIC-FULFIL-001',
        'status' => 'success',
        'amount' => 4_500,
        'currency' => 'GHS',
        'channel' => 'mobile_money',
        'paid_at' => now()->toIso8601String(),
    ], $overrides));
}

// ---------------------------------------------------------------- Successful

it('produces exactly one of each financial record', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    expect(CreditTransaction::count())->toBe(1)
        ->and(CreditLot::count())->toBe(1)
        // Two cash entries: money in, then immediately out to buy credits.
        ->and(CashTransaction::count())->toBe(2)
        ->and(CreditPurchase::fulfilled()->count())->toBe(1);
});

it('posts the credits as a purchase, not a promotion', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $transaction = CreditTransaction::first();

    expect($transaction->type)->toBe(CreditTransactionType::Purchase)
        ->and($transaction->amount)->toBe(500)
        ->and($transaction->balance_after)->toBe(500);
});

/*
 * Credits that were paid for. Classifying them as promotional would make them
 * expire and change how a refund would treat them.
 */
it('creates a purchased lot that does not expire', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $lot = CreditLot::first();

    expect($lot->source_type)->toBe(CreditLotSource::Purchased)
        ->and($lot->original_amount)->toBe(500)
        ->and($lot->remaining_amount)->toBe(500)
        ->and($lot->expires_at)->toBeNull();
});

it('links the ledger entry back to the purchase', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $transaction = CreditTransaction::first();

    expect($transaction->reference_type)->toBe(CreditPurchase::class)
        ->and($transaction->reference_id)->toBe($this->purchase->id)
        ->and($this->purchase->creditTransactions()->count())->toBe(1);
});

it('grants the credits from the snapshot, not the current package', function (): void {
    $this->purchase->package->update(['credit_amount' => 9_999, 'price_minor' => 99_999]);

    app(FulfillCreditPurchase::class)->handle($this->purchase);

    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(500);
});

it('records the real-money side separately from the credits', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $deposit = CashTransaction::where('type', CashTransactionType::Deposit)->first();
    $spend = CashTransaction::where('type', CashTransactionType::CreditPurchase)->first();

    expect($deposit->amount_minor)->toBe(4_500)
        ->and($spend->amount_minor)->toBe(-4_500)
        // Nets to zero: the customer holds credits now, not a cash balance.
        ->and($this->customer->cashWallet->fresh()->balance_minor)->toBe(0);
});

it('marks the purchase fulfilled only once credits exist', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $purchase = $this->purchase->fresh();

    expect($purchase->status)->toBe(CreditPurchaseStatus::Fulfilled)
        ->and($purchase->fulfilled_at)->not->toBeNull()
        ->and($purchase->paid_at)->not->toBeNull()
        ->and($purchase->provider_transaction_id)->toBe(987654321)
        ->and($purchase->provider_channel)->toBe('mobile_money');
});

it('records the lifecycle transitions', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $statuses = $this->purchase->transitions()->orderBy('id')->pluck('to_status')->all();

    expect($statuses)->toContain(CreditPurchaseStatus::Paid)
        ->and($statuses)->toContain(CreditPurchaseStatus::Fulfilled);
});

it('writes an audit entry', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $entry = Activity::where('log_name', 'credit_purchase')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties->get('credits'))->toBe(500)
        ->and($entry->properties->get('amount_minor'))->toBe(4_500);
});

// -------------------------------------------------------------- Idempotency

it('grants credits once however many times fulfilment is attempted', function (): void {
    foreach (range(1, 5) as $ignored) {
        app(FulfillCreditPurchase::class)->handle($this->purchase->fresh());
    }

    expect(CreditTransaction::count())->toBe(1)
        ->and(CreditLot::count())->toBe(1)
        ->and(CashTransaction::count())->toBe(2)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(500);
});

it('reports an already-fulfilled purchase without doing anything', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $result = app(FulfillCreditPurchase::class)->handle($this->purchase->fresh());

    expect($result['already_fulfilled'])->toBeTrue()
        ->and(CreditTransaction::count())->toBe(1);
});

// ------------------------------------------------------------- Verification

it('refuses a payment the provider does not call successful', function (string $status): void {
    fakeVerify(['status' => $status]);

    expect(fn (): array => app(FulfillCreditPurchase::class)->handle($this->purchase))
        ->toThrow(PaymentVerificationFailed::class);

    expect(CreditTransaction::count())->toBe(0)
        ->and($this->purchase->fresh()->status)->toBe(CreditPurchaseStatus::PaymentProcessing);
})->with(['failed', 'abandoned', 'pending', 'reversed']);

it('refuses a mismatched reference', function (): void {
    fakeVerify(['reference' => 'SOMETHING-ELSE']);

    expect(fn (): array => app(FulfillCreditPurchase::class)->handle($this->purchase))
        ->toThrow(PaymentVerificationFailed::class);

    expect(CreditTransaction::count())->toBe(0);
});

/*
 * The amount is checked against the purchase snapshot. A payment for less than
 * was agreed does not buy the package.
 */
it('refuses a mismatched amount', function (int $amount): void {
    fakeVerify(['amount' => $amount]);

    expect(fn (): array => app(FulfillCreditPurchase::class)->handle($this->purchase))
        ->toThrow(PaymentVerificationFailed::class);

    expect(CreditTransaction::count())->toBe(0)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(0);
})->with(['underpaid' => 100, 'overpaid' => 999_999, 'nothing' => 1]);

it('refuses a mismatched currency', function (): void {
    fakeVerify(['currency' => 'NGN']);

    expect(fn (): array => app(FulfillCreditPurchase::class)->handle($this->purchase))
        ->toThrow(PaymentVerificationFailed::class);

    expect(CreditTransaction::count())->toBe(0);
});

it('leaves nothing behind when verification fails', function (): void {
    fakeVerify(['amount' => 1]);

    try {
        app(FulfillCreditPurchase::class)->handle($this->purchase);
    } catch (PaymentVerificationFailed) {
        // expected
    }

    expect(CreditTransaction::count())->toBe(0)
        ->and(CreditLot::count())->toBe(0)
        ->and(CashTransaction::count())->toBe(0)
        ->and($this->purchase->fresh()->fulfilled_at)->toBeNull();
});

// --------------------------------------------------------- Failure recovery

/*
 * A payment that was taken but whose credits failed to post must stay visibly
 * outstanding, never marked complete, so a retry can put it right.
 */
it('rolls back completely when posting credits fails', function (): void {
    fakeVerify();

    // The payment verifies, then the ledger write fails. The cash entries are
    // already written at that point, so this proves the whole thing unwinds
    // rather than leaving money recorded against credits that never arrived.
    $this->partialMock(
        CreditLedgerService::class,
        fn ($mock) => $mock->shouldReceive('addCredits')
            ->andThrow(new RuntimeException('Ledger unavailable.')),
    );

    expect(fn (): array => app(FulfillCreditPurchase::class)->handle($this->purchase))
        ->toThrow(RuntimeException::class);

    expect(CreditTransaction::count())->toBe(0)
        ->and(CreditLot::count())->toBe(0)
        ->and(CashTransaction::count())->toBe(0)
        // Left visibly outstanding, never marked complete, so a retry can put
        // it right.
        ->and(CreditPurchase::find($this->purchase->id)->status)->not->toBe(CreditPurchaseStatus::Fulfilled)
        ->and(CreditPurchase::find($this->purchase->id)->fulfilled_at)->toBeNull();
});

it('can be retried successfully after the ledger recovers', function (): void {
    fakeVerify();

    $this->partialMock(
        CreditLedgerService::class,
        fn ($mock) => $mock->shouldReceive('addCredits')
            ->andThrow(new RuntimeException('Ledger unavailable.')),
    );

    try {
        app(FulfillCreditPurchase::class)->handle($this->purchase);
    } catch (RuntimeException) {
        // expected
    }

    // The ledger recovers. Rebinding the real service rather than refreshing
    // the whole application, which would abandon the test transaction and
    // leave its locks held.
    $this->app->forgetInstance(CreditLedgerService::class);
    $this->app->bind(
        CreditLedgerService::class,
        fn ($app) => new CreditLedgerService(
            $app->make(CreditAllocationService::class),
        ),
    );

    app(FulfillCreditPurchase::class)->handle(CreditPurchase::find($this->purchase->id));

    expect(CreditTransaction::count())->toBe(1)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(500)
        ->and(CreditPurchase::find($this->purchase->id)->status)->toBe(CreditPurchaseStatus::Fulfilled);
});

it('succeeds on a retry after a transient failure', function (): void {
    // First attempt: the provider is unreachable.
    fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Down'], 503)]);

    try {
        app(FulfillCreditPurchase::class)->handle($this->purchase);
    } catch (Throwable) {
        // expected
    }

    expect(CreditTransaction::count())->toBe(0)
        ->and($this->purchase->fresh()->status)->toBe(CreditPurchaseStatus::PaymentProcessing);

    // Second attempt: the provider answers.
    fakeVerify();

    app(FulfillCreditPurchase::class)->handle($this->purchase->fresh());

    expect(CreditTransaction::count())->toBe(1)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(500)
        ->and($this->purchase->fresh()->status)->toBe(CreditPurchaseStatus::Fulfilled);
});

// ------------------------------------------------------------ Ledger rules

it('goes through the ledger rather than writing a balance', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $wallet = creditWalletFor($this->customer)->fresh();
    $ledgerSum = (int) CreditTransaction::where('credit_wallet_id', $wallet->id)->sum('amount');
    $lotsRemaining = (int) CreditLot::where('credit_wallet_id', $wallet->id)->sum('remaining_amount');

    // The three representations agree, which they only can if the credits went
    // through the ledger service.
    expect($wallet->balance)->toBe(500)
        ->and($ledgerSum)->toBe(500)
        ->and($lotsRemaining)->toBe(500);
});

it('passes reconciliation afterwards', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $report = app(CreditLedgerReconciler::class)
        ->reconcile(creditWalletFor($this->customer)->fresh());

    expect($report->isHealthy())->toBeTrue();
});

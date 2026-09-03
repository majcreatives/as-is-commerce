<?php

declare(strict_types=1);

use App\Domain\Credit\Services\CreditLedgerReconciler;
use App\Domain\Payments\Actions\FulfillCreditPurchase;
use App\Domain\Shared\Idempotency\ConcurrentOperationInProgress;
use App\Enums\CreditPurchaseStatus;
use App\Enums\IdempotencyStatus;
use App\Models\CashTransaction;
use App\Models\CreditLot;
use App\Models\CreditPurchase;
use App\Models\CreditTransaction;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * What happens when the same payment is fulfilled twice at once.
 *
 * Realistic, not hypothetical: Paystack can deliver a webhook while the
 * customer's browser is arriving at the callback, and both paths call the same
 * fulfilment action. Exactly one may produce credits.
 *
 * Truncation rather than a wrapping transaction, for the reason given in the
 * credit concurrency suite: a second connection cannot see uncommitted rows.
 */

beforeEach(function (): void {
    seedRoles();
    config(['paystack.secret_key' => 'sk_test_stage_four']);

    $this->customer = User::factory()->create();

    $this->purchase = CreditPurchase::factory()->awaitingPayment()->create([
        'user_id' => $this->customer->id,
        'credit_amount' => 500,
        'amount_minor' => 4_500,
        'currency' => 'GHS',
        'provider_reference' => 'AIC-CONCURRENT-001',
    ]);

    fakePaystackVerify([
        'id' => 424242,
        'reference' => 'AIC-CONCURRENT-001',
        'status' => 'success',
        'amount' => 4_500,
        'currency' => 'GHS',
        'channel' => 'mobile_money',
        'paid_at' => now()->toIso8601String(),
    ]);
});

it('produces one financial effect when fulfilment runs repeatedly', function (): void {
    foreach (range(1, 6) as $ignored) {
        app(FulfillCreditPurchase::class)->handle(CreditPurchase::find($this->purchase->id));
    }

    expect(CreditTransaction::count())->toBe(1)
        ->and(CreditLot::count())->toBe(1)
        ->and(CashTransaction::count())->toBe(2)
        ->and((int) CreditTransaction::sum('amount'))->toBe(500)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(500);
});

/*
 * The idempotency claim is what serializes two simultaneous fulfilments. While
 * one holds the key, the other is told to wait rather than proceeding beside
 * it -- which is the moment a duplicate grant would otherwise happen.
 */
it('refuses a second fulfilment while the first is still in flight', function (): void {
    IdempotencyKey::create([
        'operation' => FulfillCreditPurchase::OPERATION,
        'idempotency_key' => $this->purchase->idempotency_key,
        'user_id' => $this->customer->id,
        'status' => IdempotencyStatus::Pending,
    ]);

    expect(fn (): array => app(FulfillCreditPurchase::class)->handle($this->purchase))
        ->toThrow(ConcurrentOperationInProgress::class);

    expect(CreditTransaction::count())->toBe(0)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(0);
});

it('serializes access to the purchase row across connections', function (): void {
    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');

    DB::beginTransaction();
    DB::table('credit_purchases')->where('id', $this->purchase->id)->lockForUpdate()->first();

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')
            ->table('credit_purchases')
            ->where('id', $this->purchase->id)
            ->lockForUpdate()
            ->first();
    } catch (Throwable $e) {
        $blocked = str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }

    DB::connection('second')->rollBack();
    DB::rollBack();
    DB::purge('second');

    expect($blocked)->toBeTrue('A second connection must not take the purchase lock while it is held.');
});

it('leaves the purchase consistent after repeated fulfilment', function (): void {
    app(FulfillCreditPurchase::class)->handle($this->purchase);
    app(FulfillCreditPurchase::class)->handle($this->purchase->fresh());

    $purchase = CreditPurchase::find($this->purchase->id);

    expect($purchase->status)->toBe(CreditPurchaseStatus::Fulfilled)
        // One fulfilment transition, not two.
        ->and($purchase->transitions()->where('to_status', CreditPurchaseStatus::Fulfilled)->count())->toBe(1)
        ->and($purchase->creditTransactions()->count())->toBe(1);
});

it('keeps the ledger reconcilable after concurrent attempts', function (): void {
    foreach (range(1, 3) as $ignored) {
        app(FulfillCreditPurchase::class)->handle(CreditPurchase::find($this->purchase->id));
    }

    $report = app(CreditLedgerReconciler::class)
        ->reconcile(creditWalletFor($this->customer)->fresh());

    expect($report->isHealthy())->toBeTrue()
        ->and($report->storedBalance)->toBe(500);
});

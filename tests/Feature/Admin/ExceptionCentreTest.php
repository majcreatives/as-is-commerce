<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Credit\Services\CreditLedgerReconciler;
use App\Domain\Operations\Queries\ExceptionCentre;
use App\Domain\Operations\ValueObjects\OperationalException;
use App\Domain\Operations\ValueObjects\Severity;
use App\Domain\Refunds\Actions\ProcessRefund;
use App\Enums\WebhookProcessingStatus;
use App\Livewire\Admin\Operations\ExceptionCentrePage;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\PaymentWebhookEvent;
use App\Models\StoreWallet;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The exception centre.
 *
 * It detects and it reports. These tests hold it to that: an exception appears
 * because a real situation is true, it disappears when the situation stops
 * being true, and there is no way to make one go away without resolving it.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

/**
 * @param  list<OperationalException>  $exceptions
 */
function typesIn(array $exceptions): array
{
    return array_map(fn (OperationalException $e): string => $e->type, $exceptions);
}

// --------------------------------------------------------------- Access

it('is reachable by an administrator', function (): void {
    $this->actingAs($this->admin)->get(route('admin.exceptions'))->assertOk();
});

it('is refused to a customer', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.exceptions'))
        ->assertForbidden();
});

it('is refused to staff without the permission', function (): void {
    Livewire::actingAs(staffWith(['orders.view']))
        ->test(ExceptionCentrePage::class)
        ->assertForbidden();
});

// --------------------------------------------------------------- Detection

it('shows nothing on a platform with nothing wrong', function (): void {
    expect(app(ExceptionCentre::class)->all())->toBe([]);

    Livewire::actingAs($this->admin)
        ->test(ExceptionCentrePage::class)
        ->assertOk()
        ->assertSee('Nothing needs attention');
});

it('reports a paid order that could not be delivered against', function (): void {
    $order = blockedPaidOrder();

    $exceptions = app(ExceptionCentre::class)->all('payments');

    expect(typesIn($exceptions))->toContain('fulfilment_blocked')
        ->and($exceptions[0]->severity)->toBe(Severity::Critical)
        ->and($exceptions[0]->reference)->toBe($order->order_number);
});

it('stops reporting a blocked order once a refund is under way', function (): void {
    $order = blockedPaidOrder();

    expect(app(ExceptionCentre::class)->all('payments'))->toHaveCount(1);

    requestRefund($order, actor: $this->admin);

    // Not dismissed -- the situation genuinely changed. Somebody decided.
    expect(app(ExceptionCentre::class)->all('payments'))->toBe([]);
});

it('reports a refund the provider refused', function (): void {
    $refund = requestRefund(blockedPaidOrder(), actor: $this->admin);

    fakePaystackRefund(paystackRefundBody($refund->amount_minor, 'failed', 'RF-REFUSED'));
    app(ProcessRefund::class)->handle($refund->fresh(), $this->admin);

    expect(typesIn(app(ExceptionCentre::class)->all('refunds')))->toContain('refund_failed');
});

it('raises nothing against healthy stock, and the database refuses the state it looks for', function (): void {
    $product = stockedProduct(stock: 2);
    app(InventoryService::class)->reserve($product->fresh(), 1, reason: 'Stage 14 test');

    expect(app(ExceptionCentre::class)->all('inventory'))->toBe([]);

    // The impossible_stock detector is an invariant check rather than a code
    // path anything can reach: reserving beyond what is on hand is refused by
    // the database itself, not merely by the service. The detector stays
    // because an invariant nobody verifies is a hope -- it would catch a state
    // arriving from outside the application, a restore or a dropped
    // constraint -- but nothing the platform does can produce one.
    expect(fn () => $product->fresh()->forceFill(['stock_reserved' => 5])->saveQuietly())
        ->toThrow(QueryException::class);

    expect(app(ExceptionCentre::class)->all('inventory'))->toBe([]);
});

it('counts each category and totals them', function (): void {
    blockedPaidOrder();

    $counts = app(ExceptionCentre::class)->counts();

    expect($counts['payments'])->toBe(1)
        ->and($counts['total'])->toBe(array_sum(array_diff_key($counts, ['total' => null])));
});

it('orders the most serious first', function (): void {
    blockedPaidOrder();

    $exceptions = app(ExceptionCentre::class)->all();
    $ranks = array_map(fn (OperationalException $e): int => $e->severity->rank(), $exceptions);
    $sorted = $ranks;
    sort($sorted);

    expect($ranks)->toBe($sorted);
});

// --------------------------------------------------------------- Providers

it('asks no payment provider anything when it loads', function (): void {
    requestRefund(blockedPaidOrder(), actor: $this->admin);

    fakeHttp(['*' => Http::response(['status' => true, 'data' => []])]);

    Livewire::actingAs($this->admin)->test(ExceptionCentrePage::class)->assertOk();

    // The page is most needed on the worst day, which is exactly the day fifty
    // synchronous API calls would make it unusable.
    Http::assertNothingSent();
});

// --------------------------------------------------------------- Safety

it('offers no way to dismiss, acknowledge or repair anything', function (): void {
    foreach (['dismiss', 'acknowledge', 'resolve', 'repair', 'fix', 'markHandled'] as $method) {
        expect(method_exists(ExceptionCentrePage::class, $method))
            ->toBeFalse("ExceptionCentrePage must not expose {$method}()");

        expect(method_exists(ExceptionCentre::class, $method))
            ->toBeFalse("ExceptionCentre must not expose {$method}()");
    }
});

it('carries nothing sensitive in what it reports', function (): void {
    $order = blockedPaidOrder();

    $rendered = Livewire::actingAs($this->admin)
        ->test(ExceptionCentrePage::class)
        ->assertSee($order->order_number)
        ->html();

    foreach ([config('paystack.secret_key'), 'password', 'remember_token'] as $secret) {
        expect($rendered)->not->toContain((string) $secret);
    }
});

// --------------------------------------------------------------- Webhooks

it('reports a failed webhook event', function (): void {
    PaymentWebhookEvent::create([
        'provider' => 'paystack',
        'provider_event_id' => 'evt_test_123',
        'event_type' => 'charge.success',
        'payload' => ['event' => 'charge.success'],
        'processing_status' => WebhookProcessingStatus::Failed,
        'processing_error' => 'Purchase not found',
        'received_at' => now(),
    ]);

    $exceptions = app(ExceptionCentre::class)->all('webhooks');

    expect(typesIn($exceptions))->toContain('webhook_processing_failed')
        ->and($exceptions[0]->severity)->toBe(Severity::Warning)
        ->and($exceptions[0]->detail)->toContain('Purchase not found');
});

it('does not report processed or ignored webhook events', function (): void {
    PaymentWebhookEvent::create([
        'provider' => 'paystack',
        'provider_event_id' => 'evt_test_456',
        'event_type' => 'charge.success',
        'payload' => ['event' => 'charge.success'],
        'processing_status' => WebhookProcessingStatus::Processed,
        'received_at' => now(),
    ]);

    PaymentWebhookEvent::create([
        'provider' => 'paystack',
        'provider_event_id' => 'evt_test_789',
        'event_type' => 'refund.created',
        'payload' => ['event' => 'refund.created'],
        'processing_status' => WebhookProcessingStatus::Ignored,
        'received_at' => now(),
    ]);

    $exceptions = app(ExceptionCentre::class)->all('webhooks');

    expect($exceptions)->toBeEmpty();
});

// --------------------------------------------------------- Store Wallet

it('reports a store wallet with a projection divergence', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $wallet = StoreWallet::where('user_id', $user->id)->first();
    $wallet->permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance_minor = 0;
        $wallet->save();
    });

    $exceptions = app(ExceptionCentre::class)->all('store_wallet');

    expect(typesIn($exceptions))->toContain('projection_divergence')
        ->and($exceptions[0]->severity)->toBe(Severity::Critical)
        ->and($exceptions[0]->detail)->toContain('Store Wallet #'.$wallet->id);
});

it('does not report healthy store wallets', function (): void {
    $user = userWithRole('customer');
    fundStoreWallet($user, 500);

    $exceptions = app(ExceptionCentre::class)->all('store_wallet');

    expect($exceptions)->toBeEmpty();
});

it('includes webhooks and store_wallet in counts', function (): void {
    PaymentWebhookEvent::create([
        'provider' => 'paystack',
        'provider_event_id' => 'evt_count_test',
        'event_type' => 'charge.success',
        'payload' => [],
        'processing_status' => WebhookProcessingStatus::Failed,
        'processing_error' => 'test',
        'received_at' => now(),
    ]);

    $counts = app(ExceptionCentre::class)->counts();

    expect($counts)->toHaveKey('webhooks')
        ->and($counts)->toHaveKey('store_wallet')
        ->and($counts['webhooks'])->toBeGreaterThanOrEqual(1);
});

/*
 * Credit balance drift.
 *
 * The store wallet has reported its own projection divergence here since it was
 * built. The credit side never did, which is the more serious of the two: a
 * Store Wallet figure that is wrong misstates a number on a screen, whereas
 * credits are what a bid spends, so the same drift decides whether a customer
 * can bid or is refused -- and it was only ever detectable by opening that
 * one customer's wallet and clicking reconcile, so a wallet nobody happened to
 * look at was not merely unreported, it was unreachable.
 *
 * These tests drift a balance on purpose. The model guard that makes the
 * materialized balance append-only has a narrow, explicit test-only escape
 * hatch for exactly this, and the last test here is the one that matters most:
 * the database must refuse the state these tests are looking for.
 */

it('reports a credit wallet whose balance has drifted from its ledger', function (): void {
    $user = bidder();

    $wallet = CreditWallet::where('user_id', $user->id)->firstOrFail();
    expect($wallet->balance)->toBeGreaterThan(0);

    CreditWallet::permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance = $wallet->balance + 500;
        $wallet->save();
    });

    $exceptions = app(ExceptionCentre::class)->all('credit_wallet');

    expect(typesIn($exceptions))->toContain('projection_divergence')
        ->and($exceptions[0]->severity)->toBe(Severity::Critical)
        ->and($exceptions[0]->detail)->toContain('Credit Wallet #'.$wallet->id)
        // The figure is stated, because "something is wrong with a balance" is
        // not actionable and "it is 500 too high" is a place to start.
        ->and($exceptions[0]->detail)->toContain((string) $wallet->balance);
});

it('does not report a credit wallet that agrees with its ledger', function (): void {
    bidder();

    expect(app(ExceptionCentre::class)->all('credit_wallet'))->toBeEmpty();
});

it('includes credit_wallet in counts and totals', function (): void {
    $user = bidder();
    $wallet = CreditWallet::where('user_id', $user->id)->firstOrFail();

    CreditWallet::permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance = 999_999;
        $wallet->save();
    });

    $counts = app(ExceptionCentre::class)->counts();

    expect($counts['credit_wallet'] ?? 0)->toBe(1);
});

it('cannot have its ledger edited, so drift can only come from a bad write', function (): void {
    // A ledger row cannot be updated -- not by the application, and not by
    // anybody who reaches the database directly. That is enforced by a trigger,
    // not by convention, and it is the reason the projection check exists at
    // all: if the ledger cannot be edited, the only way a materialized balance
    // can stop matching it is a bug in the code that writes them.
    $user = bidder();
    $wallet = CreditWallet::where('user_id', $user->id)->firstOrFail();

    expect(fn (): int => CreditTransaction::where('credit_wallet_id', $wallet->id)
        ->update(['amount' => -5000]))
        ->toThrow(QueryException::class);
});

it('catches a ledger that sums below zero, which is a write bug not an edit', function (): void {
    // The trigger above blocks edits, but nothing stops the code that writes
    // new rows from writing a wrong one -- and an INSERT is exactly what a
    // buggy deduction path would do. A ledger summing below zero means credits
    // were taken that should not have been, which the unsigned balance column
    // cannot represent, so it would otherwise be invisible until somebody
    // tried to spend them.
    //
    // Written through the model, bypassing the service, on purpose: the point
    // is that a row *can* be inserted and the detector must still see it.
    $user = bidder();
    $wallet = CreditWallet::where('user_id', $user->id)->firstOrFail();

    CreditTransaction::create([
        'credit_wallet_id' => $wallet->id,
        'type' => 'purchase',
        'amount' => -($wallet->balance + 10_000),
        'balance_after' => 0,
        'idempotency_key' => 'test_bogus_deduction_'.$wallet->id,
    ]);

    expect(app(CreditLedgerReconciler::class)->projectionMismatches())->not->toBeEmpty();
});

it('bounds the wallet sweep, so it cannot grow into an outage', function (): void {
    // An operations screen is needed most on the worst day, which is the day a
    // full pass over every customer's history would take the site down with it.
    $user = bidder();
    $wallet = CreditWallet::where('user_id', $user->id)->firstOrFail();

    CreditWallet::permittingBalanceWrites(function () use ($wallet): void {
        $wallet->balance = 4242;
        $wallet->save();
    });

    expect(app(CreditLedgerReconciler::class)->projectionMismatches())->toHaveCount(1)
        ->and(app(CreditLedgerReconciler::class)->projectionMismatches(0))->toBeEmpty();
});

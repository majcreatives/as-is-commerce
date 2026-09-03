<?php

declare(strict_types=1);

use App\Domain\Payments\Actions\FulfillCreditPurchase;
use App\Enums\CreditPurchaseStatus;
use App\Livewire\Credits\PurchaseHistory;
use App\Models\CreditPurchase;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
    config(['paystack.secret_key' => 'sk_test_stage_four']);

    $this->customer = userWithRole('customer');

    $this->purchase = CreditPurchase::factory()->awaitingPayment()->create([
        'user_id' => $this->customer->id,
        'package_name_snapshot' => 'Popular',
        'credit_amount' => 500,
        'amount_minor' => 4_500,
        'currency' => 'GHS',
        'provider_reference' => 'AIC-CALLBACK-001',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeCallbackVerify(array $overrides = []): void
{
    fakePaystackVerify(array_replace([
        'id' => 5551212,
        'reference' => 'AIC-CALLBACK-001',
        'status' => 'success',
        'amount' => 4_500,
        'currency' => 'GHS',
        'channel' => 'mobile_money',
        'paid_at' => now()->toIso8601String(),
    ], $overrides));
}

// ------------------------------------------------------------------- Access

it('requires authentication', function (): void {
    $this->get('/credits/callback?reference=AIC-CALLBACK-001')->assertRedirect(route('login'));
});

/*
 * A reference is not a capability. Someone else's must not become viewable, or
 * fulfillable, by pasting it into the URL.
 */
it('does not show another customer their payment', function (): void {
    $intruder = userWithRole('customer');

    $this->actingAs($intruder)
        ->get('/credits/callback?reference=AIC-CALLBACK-001')
        ->assertOk()
        ->assertSee('Payment not found');

    expect(CreditTransaction::count())->toBe(0);
});

it('handles a missing reference gracefully', function (): void {
    $this->actingAs($this->customer)
        ->get('/credits/callback')
        ->assertOk()
        ->assertSee('Payment not found');
});

it('handles an unknown reference gracefully', function (): void {
    $this->actingAs($this->customer)
        ->get('/credits/callback?reference=NOT-A-REAL-REFERENCE')
        ->assertOk()
        ->assertSee('Payment not found');
});

// -------------------------------------------------------------- Verified

it('grants credits through the same verified path the webhook uses', function (): void {
    fakeCallbackVerify();

    $this->actingAs($this->customer)
        ->get('/credits/callback?reference=AIC-CALLBACK-001')
        ->assertOk()
        ->assertSee('Payment confirmed');

    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(500)
        ->and($this->purchase->fresh()->status)->toBe(CreditPurchaseStatus::Fulfilled);

    // It asked the provider; it did not take the browser's word for it.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/transaction/verify/'));
});

/*
 * The point of the callback rule: arriving here successfully is not payment.
 */
it('grants nothing when the provider says the payment did not succeed', function (): void {
    fakeCallbackVerify(['status' => 'abandoned']);

    $this->actingAs($this->customer)
        ->get('/credits/callback?reference=AIC-CALLBACK-001')
        ->assertOk()
        ->assertSee('Payment not confirmed yet');

    expect(CreditTransaction::count())->toBe(0)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(0);
});

it('ignores an amount supplied in the query string', function (): void {
    fakeCallbackVerify();

    $this->actingAs($this->customer)
        ->get('/credits/callback?reference=AIC-CALLBACK-001&amount=999999&credits=99999')
        ->assertOk();

    // The snapshot decided, not the URL.
    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(500);
});

it('shows a pending state when the provider cannot be reached', function (): void {
    fakeHttp(['api.paystack.co/*' => Http::response(['status' => false], 503)]);

    $this->actingAs($this->customer)
        ->get('/credits/callback?reference=AIC-CALLBACK-001')
        ->assertOk()
        ->assertSee('Payment not confirmed yet');

    expect(CreditTransaction::count())->toBe(0);
});

// ------------------------------------------------------------ Idempotency

it('does not grant credits twice when the customer refreshes', function (): void {
    fakeCallbackVerify();

    foreach (range(1, 4) as $ignored) {
        $this->actingAs($this->customer)
            ->get('/credits/callback?reference=AIC-CALLBACK-001')
            ->assertOk();
    }

    expect(CreditTransaction::count())->toBe(1)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(500);
});

it('does not grant credits twice when the webhook has already fulfilled', function (): void {
    fakeCallbackVerify();

    // The webhook got there first.
    app(FulfillCreditPurchase::class)->handle($this->purchase);

    $this->actingAs($this->customer)
        ->get('/credits/callback?reference=AIC-CALLBACK-001')
        ->assertOk()
        ->assertSee('Payment confirmed');

    expect(CreditTransaction::count())->toBe(1)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(500);
});

// -------------------------------------------------------- Purchase history

it('shows a customer their own purchases', function (): void {
    Livewire::actingAs($this->customer)
        ->test(PurchaseHistory::class)
        ->assertOk()
        ->assertSee('Popular')
        ->assertSee('AIC-CALLBACK-001')
        ->assertSee('500');
});

it('never shows one customer another customer purchases', function (): void {
    $other = User::factory()->create();
    CreditPurchase::factory()->fulfilled()->create([
        'user_id' => $other->id,
        'provider_reference' => 'AIC-SOMEONE-ELSE',
    ]);

    Livewire::actingAs($this->customer)
        ->test(PurchaseHistory::class)
        ->assertDontSee('AIC-SOMEONE-ELSE');
});

it('shows an empty state before any purchases', function (): void {
    $fresh = userWithRole('customer');

    Livewire::actingAs($fresh)
        ->test(PurchaseHistory::class)
        ->assertSee('You have not bought any credits yet');
});

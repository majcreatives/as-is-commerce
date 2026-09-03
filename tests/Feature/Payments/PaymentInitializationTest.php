<?php

declare(strict_types=1);

use App\Domain\Payments\Actions\InitializeCreditPurchase;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Enums\CreditPurchaseStatus;
use App\Livewire\Credits\CreditPackages;
use App\Models\CreditPackage;
use App\Models\CreditPurchase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_stage_four']);

    $this->customer = userWithRole('customer');
    $this->package = CreditPackage::factory()->priced(500, 4_500)->create(['name' => 'Popular']);

    fakeHttp([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/abc123',
                'access_code' => 'abc123',
                'reference' => 'server-generated',
            ],
        ]),
    ]);
});

// ------------------------------------------------------------------- Happy

it('creates a pending purchase carrying an immutable snapshot', function (): void {
    $result = app(InitializeCreditPurchase::class)->handle($this->customer, $this->package);

    $purchase = $result['purchase'];

    expect($purchase->package_name_snapshot)->toBe('Popular')
        ->and($purchase->credit_amount)->toBe(500)
        ->and($purchase->amount_minor)->toBe(4_500)
        ->and($purchase->currency)->toBe('GHS')
        ->and($purchase->status)->toBe(CreditPurchaseStatus::PaymentProcessing)
        ->and($purchase->user_id)->toBe($this->customer->id);
});

it('returns somewhere for the customer to pay', function (): void {
    $result = app(InitializeCreditPurchase::class)->handle($this->customer, $this->package);

    expect($result['transaction']->authorizationUrl)->toBe('https://checkout.paystack.com/abc123')
        ->and($result['transaction']->accessCode)->toBe('abc123');
});

it('generates a unique, unguessable reference', function (): void {
    $first = app(InitializeCreditPurchase::class)->handle($this->customer, $this->package)['purchase'];
    $second = app(InitializeCreditPurchase::class)->handle($this->customer, $this->package)['purchase'];

    expect($first->provider_reference)->not->toBe($second->provider_reference)
        // Long enough not to be guessable, and carrying nothing sensitive.
        ->and(strlen($first->provider_reference))->toBeGreaterThan(20)
        ->and($first->provider_reference)->not->toContain((string) $this->customer->id);
});

it('records the state transition', function (): void {
    $purchase = app(InitializeCreditPurchase::class)->handle($this->customer, $this->package)['purchase'];

    expect($purchase->transitions()->where('to_status', CreditPurchaseStatus::PaymentProcessing)->exists())
        ->toBeTrue();
});

// ------------------------------------------------------------ Amount safety

/*
 * The amount sent to the provider comes from the server-side package record.
 * There is no parameter through which a browser could influence it.
 */
it('sends the server-side package price to the provider', function (): void {
    app(InitializeCreditPurchase::class)->handle($this->customer, $this->package);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/transaction/initialize')
            && $request['amount'] === 4_500
            && $request['currency'] === 'GHS';
    });
});

it('ignores any amount the browser tries to supply', function (): void {
    // The component's only input is a slug. A crafted request carrying an
    // amount or a credit quantity has nowhere to put it.
    Livewire::actingAs($this->customer)
        ->test(CreditPackages::class)
        ->call('purchase', $this->package->slug);

    $purchase = CreditPurchase::firstWhere('user_id', $this->customer->id);

    expect($purchase->amount_minor)->toBe(4_500)
        ->and($purchase->credit_amount)->toBe(500);

    Http::assertSent(fn ($request): bool => $request['amount'] === 4_500);
});

/*
 * The snapshot is what makes historical purchases stable. Repricing a package
 * must not change what an already-open purchase costs or grants.
 */
it('is unaffected by the package being repriced afterwards', function (): void {
    $purchase = app(InitializeCreditPurchase::class)->handle($this->customer, $this->package)['purchase'];

    $this->package->update(['price_minor' => 5_000, 'credit_amount' => 100]);

    expect($purchase->fresh()->amount_minor)->toBe(4_500)
        ->and($purchase->fresh()->credit_amount)->toBe(500)
        ->and($purchase->fresh()->package_name_snapshot)->toBe('Popular');
});

// -------------------------------------------------------------- Refusals

it('refuses to sell an inactive package', function (): void {
    $inactive = CreditPackage::factory()->inactive()->create();

    expect(fn (): array => app(InitializeCreditPurchase::class)->handle($this->customer, $inactive))
        ->toThrow(PaymentVerificationFailed::class);

    expect(CreditPurchase::count())->toBe(0);
});

it('refuses an inactive package through the storefront', function (): void {
    $inactive = CreditPackage::factory()->inactive()->create();

    Livewire::actingAs($this->customer)
        ->test(CreditPackages::class)
        ->call('purchase', $inactive->slug)
        ->assertHasErrors('package');

    expect(CreditPurchase::count())->toBe(0);
});

it('refuses a package that does not exist', function (): void {
    Livewire::actingAs($this->customer)
        ->test(CreditPackages::class)
        ->call('purchase', 'no-such-package')
        ->assertHasErrors('package');
});

it('surfaces a provider outage rather than failing silently', function (): void {
    fakeHttp([
        'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Service unavailable'], 503),
    ]);

    expect(fn (): array => app(InitializeCreditPurchase::class)->handle($this->customer, $this->package))
        ->toThrow(PaymentGatewayError::class);
});

/*
 * The purchase row is written before the provider is called, so a transaction
 * that succeeds at Paystack but fails on the way back to us still has a record
 * to be reconciled against. Losing that would lose the payment.
 */
it('keeps the purchase record when the provider call fails', function (): void {
    fakeHttp([
        'api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Nope'], 400),
    ]);

    try {
        app(InitializeCreditPurchase::class)->handle($this->customer, $this->package);
    } catch (PaymentGatewayError) {
        // expected
    }

    $purchase = CreditPurchase::first();

    expect($purchase)->not->toBeNull()
        ->and($purchase->status)->toBe(CreditPurchaseStatus::Pending);
});

it('refuses to take payment when the provider is not configured', function (): void {
    config(['paystack.secret_key' => null]);

    expect(fn (): array => app(InitializeCreditPurchase::class)->handle($this->customer, $this->package))
        ->toThrow(PaymentGatewayError::class);
});

// ---------------------------------------------------------------- Access

it('requires authentication to buy credits', function (): void {
    $this->get('/credits')->assertRedirect(route('login'));
});

it('lets a signed-in customer see the packages', function (): void {
    $this->actingAs($this->customer)->get('/credits')->assertOk();
});

it('never equates credits with money on the page', function (): void {
    $this->actingAs($this->customer)
        ->get('/credits')
        ->assertOk()
        ->assertSee('500')
        ->assertSee('45.00')
        // The page must not state or imply that 500 credits is GH 500.
        ->assertDontSee('500 credits = ')
        ->assertDontSee('Auction price');
});

it('does not leak the secret key to the browser', function (): void {
    $response = $this->actingAs($this->customer)->get('/credits');

    expect($response->getContent())->not->toContain('sk_test_stage_four');
});

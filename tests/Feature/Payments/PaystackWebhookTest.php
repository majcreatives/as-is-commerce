<?php

declare(strict_types=1);

use App\Enums\CreditPurchaseStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\CreditPurchase;
use App\Models\CreditTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    seedRoles();
    config(['paystack.secret_key' => 'sk_test_stage_four']);

    $this->customer = User::factory()->create();

    $this->purchase = CreditPurchase::factory()->awaitingPayment()->create([
        'user_id' => $this->customer->id,
        'credit_amount' => 500,
        'amount_minor' => 4_500,
        'currency' => 'GHS',
        'provider_reference' => 'AIC-TEST-REF-001',
    ]);

    // The provider's account of the transaction, which is the only evidence
    // the application accepts.
    fakePaystackVerify([
        'id' => 987654321,
        'reference' => 'AIC-TEST-REF-001',
        'status' => 'success',
        'amount' => 4_500,
        'currency' => 'GHS',
        'channel' => 'mobile_money',
        'paid_at' => now()->toIso8601String(),
    ]);
});

/**
 * POST a webhook with a correctly computed signature.
 *
 * @param  array<string, mixed>  $payload
 */
function postWebhook(array $payload, ?string $signature = null): TestResponse
{
    $raw = (string) json_encode($payload);

    return test()->call(
        'POST',
        '/webhooks/paystack',
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? paystackSignature($raw),
        ],
        $raw,
    );
}

// ---------------------------------------------------------------- Signature

it('accepts a correctly signed webhook', function (): void {
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500))
        ->assertOk()
        ->assertJson(['status' => 'processed']);
});

/*
 * The endpoint is public and unauthenticated, so the signature is the only
 * thing separating Paystack from anyone else who finds the URL.
 */
it('rejects a webhook with a wrong signature', function (): void {
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500), 'not-a-real-signature')
        ->assertStatus(401);

    expect(PaymentWebhookEvent::count())->toBe(0)
        ->and(CreditTransaction::count())->toBe(0)
        ->and($this->purchase->fresh()->status)->toBe(CreditPurchaseStatus::PaymentProcessing);
});

it('rejects a webhook with no signature at all', function (): void {
    $raw = (string) json_encode(paystackChargePayload('AIC-TEST-REF-001', 4_500));

    $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw)
        ->assertStatus(401);

    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('rejects a signature computed with the wrong secret', function (): void {
    $payload = paystackChargePayload('AIC-TEST-REF-001', 4_500);
    $raw = (string) json_encode($payload);

    postWebhook($payload, paystackSignature($raw, 'sk_test_someone_elses_key'))
        ->assertStatus(401);

    expect(CreditTransaction::count())->toBe(0);
});

/*
 * The signature covers the exact bytes sent. A body altered in transit -- an
 * amount raised, a reference swapped -- no longer matches.
 */
it('rejects a payload tampered with after signing', function (): void {
    $original = paystackChargePayload('AIC-TEST-REF-001', 4_500);
    $signature = paystackSignature((string) json_encode($original));

    $tampered = paystackChargePayload('AIC-TEST-REF-001', 999_999);

    postWebhook($tampered, $signature)->assertStatus(401);

    expect(CreditTransaction::count())->toBe(0);
});

it('rejects a signed but unreadable body', function (): void {
    $raw = 'this is not json';

    $this->call(
        'POST', '/webhooks/paystack', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => paystackSignature($raw)],
        $raw,
    )->assertStatus(400);

    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('rejects a signed body with no event type', function (): void {
    postWebhook(['data' => ['reference' => 'AIC-TEST-REF-001']])->assertStatus(400);
});

it('does not require CSRF, since a provider cannot present a token', function (): void {
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500))->assertOk();
});

// --------------------------------------------------------------- Persistence

it('stores the event it accepted', function (): void {
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500, transactionId: 555))->assertOk();

    $event = PaymentWebhookEvent::first();

    expect($event)->not->toBeNull()
        ->and($event->event_type)->toBe('charge.success')
        ->and($event->provider_event_id)->toBe('charge.success:555')
        ->and($event->processing_status)->toBe(WebhookProcessingStatus::Processed)
        ->and($event->credit_purchase_id)->toBe($this->purchase->id)
        ->and($event->processed_at)->not->toBeNull();
});

it('never stores the secret key', function (): void {
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500))->assertOk();

    $event = PaymentWebhookEvent::first();

    expect((string) json_encode($event->toArray()))->not->toContain('sk_test_stage_four');
});

// ---------------------------------------------------------------- Duplicates

/*
 * Paystack retries. Five deliveries of one event must produce one grant.
 */
it('grants credits once however many times the event is delivered', function (): void {
    $payload = paystackChargePayload('AIC-TEST-REF-001', 4_500);

    postWebhook($payload)->assertOk()->assertJson(['status' => 'processed']);

    foreach (range(1, 4) as $ignored) {
        postWebhook($payload)->assertOk()->assertJson(['status' => 'duplicate']);
    }

    expect(CreditTransaction::where('type', 'purchase')->count())->toBe(1)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(500)
        ->and(PaymentWebhookEvent::count())->toBe(1)
        ->and(CreditPurchase::find($this->purchase->id)->status)->toBe(CreditPurchaseStatus::Fulfilled);
});

it('recognises a repeat by the provider event identity', function (): void {
    $payload = paystackChargePayload('AIC-TEST-REF-001', 4_500, transactionId: 42);

    postWebhook($payload)->assertOk();
    postWebhook($payload)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(PaymentWebhookEvent::count())->toBe(1);
});

it('rejects a duplicate event at the database level', function (): void {
    PaymentWebhookEvent::factory()->create([
        'provider_event_id' => 'charge.success:99',
    ]);

    expect(fn () => PaymentWebhookEvent::factory()->create([
        'provider_event_id' => 'charge.success:99',
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- Unknown

it('records but ignores an event for a payment it does not know', function (): void {
    postWebhook(paystackChargePayload('SOMEONE-ELSES-REFERENCE', 4_500))
        ->assertOk()
        ->assertJson(['status' => 'ignored']);

    expect(CreditTransaction::count())->toBe(0)
        ->and(PaymentWebhookEvent::first()->processing_status)->toBe(WebhookProcessingStatus::Ignored);
});

it('records but does not act on an event type it does not handle', function (): void {
    $payload = paystackChargePayload('AIC-TEST-REF-001', 4_500);
    $payload['event'] = 'transfer.success';

    postWebhook($payload)->assertOk()->assertJson(['status' => 'ignored']);

    expect(CreditTransaction::count())->toBe(0);
});

it('closes out a purchase when the provider reports the charge failed', function (): void {
    $payload = paystackChargePayload('AIC-TEST-REF-001', 4_500);
    $payload['event'] = 'charge.failed';
    $payload['data']['status'] = 'failed';

    postWebhook($payload)->assertOk();

    expect($this->purchase->fresh()->status)->toBe(CreditPurchaseStatus::Failed)
        ->and(CreditTransaction::count())->toBe(0);
});

/*
 * A refund does not claw credits back. They may already have been spent, and
 * reversing a spend is a business decision rather than something to infer.
 */
it('records a refund without removing credits', function (): void {
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500))->assertOk();

    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(500);

    $refund = paystackChargePayload('AIC-TEST-REF-001', 4_500, transactionId: 777);
    $refund['event'] = 'refund.processed';

    postWebhook($refund)->assertOk();

    expect(creditWalletFor($this->customer)->fresh()->balance)->toBe(500)
        ->and(PaymentWebhookEvent::count())->toBe(2);
});

// ------------------------------------------------------------- Verification

/*
 * The event body is a claim, not evidence. Credits are granted on the strength
 * of what Paystack says when asked directly.
 */
it('verifies with the provider rather than trusting the event body', function (): void {
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500))->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/transaction/verify/AIC-TEST-REF-001'));
});

it('grants nothing when the event claims success but the provider disagrees', function (): void {
    fakePaystackVerify([
        'reference' => 'AIC-TEST-REF-001',
        'status' => 'failed',
        'amount' => 4_500,
        'currency' => 'GHS',
    ]);

    // A forged body cannot buy credits, because the body is not what is
    // believed.
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500))->assertStatus(500);

    expect(CreditTransaction::count())->toBe(0)
        ->and(creditWalletFor($this->customer)->fresh()->balance)->toBe(0)
        ->and(PaymentWebhookEvent::first()->processing_status)->toBe(WebhookProcessingStatus::Failed);
});

it('asks the provider to retry when processing fails', function (): void {
    fakeHttp([
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => false, 'message' => 'Down'], 503),
    ]);

    // 5xx so Paystack retries: the event is stored, so a retry is safe, and a
    // transient fault must not strand a paying customer without credits.
    postWebhook(paystackChargePayload('AIC-TEST-REF-001', 4_500))->assertStatus(500);

    expect(PaymentWebhookEvent::first()->processing_status)->toBe(WebhookProcessingStatus::Failed);
});

it('refuses an event whose metadata names a different purchase', function (): void {
    $other = CreditPurchase::factory()->create();

    $payload = paystackChargePayload('AIC-TEST-REF-001', 4_500);
    $payload['data']['metadata'] = ['credit_purchase_id' => $other->id];

    postWebhook($payload)->assertStatus(500);

    expect(CreditTransaction::count())->toBe(0);
});

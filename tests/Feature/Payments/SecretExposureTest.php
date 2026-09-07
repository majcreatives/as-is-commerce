<?php

declare(strict_types=1);

use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Payments\Actions\FulfillCreditPurchase;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Models\CreditPurchase;
use App\Models\PaymentWebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

/*
 * Where the Paystack secret must never turn up.
 *
 * The key authenticates every server-to-server call and verifies every webhook
 * signature. Anyone holding it can forge a payment confirmation, so the
 * question is not only whether it is stored safely but whether it can escape
 * sideways -- into a log line, an exception message a customer sees, a stored
 * webhook event, an audit record.
 *
 * These tests deliberately drive the failure paths, because that is where a
 * secret usually leaks: an exception that helpfully includes the request it
 * was making, or a log line that dumps the whole outgoing payload with its
 * Authorization header attached.
 */

const DEMO_SECRET = 'sk_test_a_very_recognisable_secret_value';

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => DEMO_SECRET]);
});

/**
 * Everything written to the log while running the given work.
 */
function loggedDuring(Closure $work): string
{
    $lines = [];

    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message.' '.json_encode($message->context);
    });

    try {
        $work();
    } catch (Throwable) {
        // The failure is the point.
    }

    return implode("\n", $lines);
}

// ------------------------------------------------------------------- Logs

it('keeps the secret out of the log when the provider is unreachable', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    fakeHttp(['api.paystack.co/*' => fn () => throw new ConnectionException('down')]);

    $logged = loggedDuring(fn () => app(FulfillOrderPayment::class)->handle($payment->fresh()));

    expect($logged)->not->toContain(DEMO_SECRET)
        // And it did log something, so this is not passing by writing nothing.
        ->and($logged)->toContain('Paystack');
});

it('keeps the secret out of the log when the provider rejects a request', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    fakeHttp([
        'api.paystack.co/transaction/verify/*' => Http::response(
            ['status' => false, 'message' => 'Invalid key'],
            401,
        ),
    ]);

    $logged = loggedDuring(fn () => app(FulfillOrderPayment::class)->handle($payment->fresh()));

    expect($logged)->not->toContain(DEMO_SECRET);
});

it('keeps the secret out of the log on the credit purchase path', function (): void {
    $purchase = CreditPurchase::factory()->awaitingPayment()->create([
        'user_id' => bidder()->id,
        'provider_reference' => 'AIC-SECRET-001',
    ]);

    fakeHttp(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'nope'], 400)]);

    $logged = loggedDuring(fn () => app(FulfillCreditPurchase::class)->handle($purchase));

    expect($logged)->not->toContain(DEMO_SECRET);
});

// ------------------------------------------------------------- Exceptions

it('never puts the secret in an exception a customer could see', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    $payment = initializePayment($order);

    // `initializePayment()` configures its own key, and the gateway reads the
    // secret when it is constructed. Set ours back before resolving the action
    // that verifies, or the redaction would be matching a key that is no
    // longer configured.
    config(['paystack.secret_key' => DEMO_SECRET]);

    fakeHttp([
        'api.paystack.co/transaction/verify/*' => Http::response(
            ['status' => false, 'message' => 'Invalid key: '.DEMO_SECRET],
            401,
        ),
    ]);

    try {
        app(FulfillOrderPayment::class)->handle($payment->fresh());
        $message = '';
    } catch (PaymentGatewayError $e) {
        $message = $e->getMessage();
    }

    // Even when the provider itself echoes the key back in an error -- which
    // it should not, but a misconfigured gateway might -- it must not reach a
    // page a customer is looking at.
    expect($message)->not->toContain(DEMO_SECRET);
});

// ------------------------------------------------------- Stored records

it('never stores the secret on a webhook event', function (): void {
    $payload = paystackChargePayload(reference: 'AIC-SECRET-002', amountMinor: 4_500);
    $raw = json_encode($payload);

    postOrderWebhook($payload);

    foreach (PaymentWebhookEvent::all() as $event) {
        expect(json_encode($event->getAttributes()))->not->toContain(DEMO_SECRET);
    }

    expect(PaymentWebhookEvent::count())->toBeGreaterThan(0);
});

it('never stores the secret in an audit record', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    payOrder($order);

    foreach (Activity::all() as $entry) {
        expect(json_encode($entry->getAttributes()))->not->toContain(DEMO_SECRET);
    }
});

it('never stores the secret on a payment or order row', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());
    payOrder($order);

    $rows = [
        json_encode($order->fresh()->getAttributes()),
        json_encode($order->fresh()->payments->first()->getAttributes()),
    ];

    foreach ($rows as $row) {
        expect($row)->not->toContain(DEMO_SECRET);
    }
});

// --------------------------------------------------- Configuration hygiene

it('reads every provider credential from configuration rather than code', function (): void {
    $source = File::get(app_path('Domain/Payments/Paystack/PaystackGateway.php'));

    // The gateway takes its key by constructor injection. A literal key in the
    // class would survive a credential rotation and reach the repository.
    expect($source)->not->toMatch('/sk_(test|live)_[A-Za-z0-9]{8,}/')
        ->and($source)->not->toMatch('/pk_(test|live)_[A-Za-z0-9]{8,}/');

    // TLS verification is never disabled: an intercepted confirmation is a
    // forged confirmation.
    expect($source)->not->toContain('withoutVerifying')
        ->and($source)->not->toContain('verify => false');
});

it('carries no credential in the committed environment example', function (): void {
    $example = File::get(base_path('.env.example'));

    expect($example)->not->toMatch('/sk_(test|live)_[A-Za-z0-9]{8,}/')
        ->and($example)->not->toMatch('/pk_(test|live)_[A-Za-z0-9]{8,}/')
        // The keys must be present but empty, so a deployment knows to fill
        // them rather than discovering the requirement at the first payment.
        ->and($example)->toContain('PAYSTACK_SECRET_KEY=')
        ->and($example)->toContain('PAYSTACK_PUBLIC_KEY=');
});

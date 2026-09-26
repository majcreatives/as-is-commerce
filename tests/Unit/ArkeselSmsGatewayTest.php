<?php

declare(strict_types=1);

use App\Domain\User\Arkesel\ArkeselSmsGateway;
use App\Domain\User\Exceptions\SmsGatewayError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The wire contract with Arkesel.
 *
 * Every assertion here is about something a real message depends on and that
 * no other test would catch: that the request reaches the documented endpoint,
 * that the key is presented the way Arkesel expects, and that anything short
 * of an explicit success counts as a failure. A gateway that reported success
 * for a message it never sent would leave customers locked out of password
 * recovery while every higher-level test still passed happily.
 */

/**
 * The gateway, wired to whatever HTTP stubs the test has installed.
 *
 * Built against the swapped factory rather than a private one: a fake on a
 * different instance than the gateway holds is a fake nothing sees.
 */
function arkeselGateway(?string $apiKey = 'test-api-key', ?string $senderId = 'AsIs'): ArkeselSmsGateway
{
    return new ArkeselSmsGateway(
        http: app(HttpFactory::class),
        apiKey: $apiKey,
        senderId: $senderId,
        baseUrl: 'https://sms.arkesel.com',
        timeout: 10,
    );
}

it('posts to the v2 send endpoint', function (): void {
    fakeHttp(['*' => Http::response(['status' => 'success'], 200)]);

    arkeselGateway()->send('+233244123456', 'Your code is 123456');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sms.arkesel.com/api/v2/sms/send');
});

it('sends the key as a bare authorization header rather than a bearer token', function (): void {
    fakeHttp(['*' => Http::response(['status' => 'success'], 200)]);

    arkeselGateway()->send('+233244123456', 'Your code is 123456');

    // Arkesel compares the header against the key itself. "Bearer <key>" is
    // read as an invalid key, so this one header is the difference between a
    // message that arrives and one that is quietly refused.
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'test-api-key'));
});

it('sends the registered sender, the canonical number and the text', function (): void {
    fakeHttp(['*' => Http::response(['status' => 'success'], 200)]);

    arkeselGateway(senderId: 'AsIsShop')->send('+233244123456', 'Your code is 123456');

    Http::assertSent(fn (Request $request): bool => $request['source'] === 'AsIsShop'
        && $request['destination'] === '+233244123456'
        && $request['message'] === 'Your code is 123456'
        && $request['type'] === 'normal');
});

it('accepts an explicit success', function (): void {
    fakeHttp(['*' => Http::response([
        'status' => 'success',
        'message' => 'Message Submitted Successfully',
        'data' => ['id' => 1],
    ], 200)]);

    arkeselGateway()->send('+233244123456', 'Your code is 123456');

    Http::assertSentCount(1);
});

it('treats a 200 that is not a success as a failure', function (): void {
    fakeHttp(['*' => Http::response([
        'status' => 'error',
        'message' => 'Invalid API Key',
    ], 200)]);

    expect(fn () => arkeselGateway()->send('+233244123456', 'x'))
        ->toThrow(SmsGatewayError::class, 'Invalid API Key');
});

it('treats a client error as a failure', function (): void {
    fakeHttp(['*' => Http::response(['message' => 'Sender not registered'], 400)]);

    expect(fn () => arkeselGateway()->send('+233244123456', 'x'))
        ->toThrow(SmsGatewayError::class, 'Sender not registered');
});

it('treats a server error as a failure', function (): void {
    fakeHttp(['*' => Http::response(['message' => 'Internal Server Error'], 500)]);

    expect(fn () => arkeselGateway()->send('+233244123456', 'x'))
        ->toThrow(SmsGatewayError::class);
});

it('treats an unreachable provider as a failure', function (): void {
    fakeHttp(['*' => fn () => throw new ConnectionException('Connection timed out')]);

    expect(fn () => arkeselGateway()->send('+233244123456', 'x'))
        ->toThrow(SmsGatewayError::class);
});

it('treats an unreadable body as a failure', function (): void {
    fakeHttp(['*' => Http::response('<html>maintenance</html>', 200)]);

    expect(fn () => arkeselGateway()->send('+233244123456', 'x'))
        ->toThrow(SmsGatewayError::class);
});

it('refuses to send without an api key', function (): void {
    expect(fn () => arkeselGateway(apiKey: null)->send('+233244123456', 'x'))
        ->toThrow(SmsGatewayError::class, 'not configured');

    // Nothing was even attempted.
    Http::assertNothingSent();
});

it('refuses to send without a registered sender', function (): void {
    expect(fn () => arkeselGateway(senderId: '')->send('+233244123456', 'x'))
        ->toThrow(SmsGatewayError::class, 'not configured');

    Http::assertNothingSent();
});

it('keeps the code out of what it logs', function (): void {
    Log::spy();

    fakeHttp(['*' => Http::response(['message' => 'Rejected by carrier'], 400)]);

    expect(fn () => arkeselGateway()->send('+233244123456', 'Your code is 987654'))
        ->toThrow(SmsGatewayError::class);

    // A provider that echoed the request back would otherwise put a live
    // one-time code into a log line. Only the provider's own short reason is
    // ever recorded, and never the message we sent.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []): bool => ! str_contains(json_encode($context), '987654'));
});

<?php

declare(strict_types=1);

namespace App\Domain\User\Arkesel;

use App\Domain\User\Contracts\SmsGateway;
use App\Domain\User\Exceptions\SmsGatewayError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Arkesel, contained.
 *
 * Every Arkesel-specific detail lives here: the endpoint, the bare
 * `Authorization` header it expects, the request body, the shape of its
 * success envelope. Nothing outside this class knows any of that, so replacing
 * the provider is a new adapter rather than an edit to the recovery flow.
 *
 * Only the plain send endpoint is used. Arkesel also sells a hosted OTP
 * product that would generate and verify the code on their side; that is
 * deliberately not used, because the code's whole security story here is that
 * this server owns it -- hashed at rest, expiring, single-use, attempt-bounded
 * and rolled back when delivery fails. A provider that held the code could do
 * none of that, and its stored copies would outlive our revocation.
 *
 * The API key is sent as a raw `Authorization` header, not a bearer token, and
 * never leaves the server. TLS verification is never disabled.
 */
final class ArkeselSmsGateway implements SmsGateway
{
    /**
     * Arkesel's v2 send endpoint, appended to the configured base URL.
     */
    private const SEND_PATH = '/api/v2/sms/send';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $apiKey,
        private readonly ?string $senderId,
        private readonly string $baseUrl = 'https://sms.arkesel.com',
        private readonly int $timeout = 10,
    ) {}

    public function send(string $e164Phone, string $message): void
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw SmsGatewayError::notConfigured();
        }

        if ($this->senderId === null || $this->senderId === '') {
            throw SmsGatewayError::notConfigured();
        }

        try {
            $response = $this->http
                // A raw Authorization header, not withToken(): Arkesel expects
                // the key itself, and "Bearer <key>" is rejected as a bad key.
                ->withHeaders(['Authorization' => $this->apiKey])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post($this->baseUrl.self::SEND_PATH, [
                    'destination' => $e164Phone,
                    'source' => $this->senderId,
                    'message' => $message,
                    'type' => 'normal',
                ]);
        } catch (ConnectionException $e) {
            // Logged without the body: the body carries the one-time code.
            Log::warning('Arkesel SMS request failed to connect', ['exception' => $e::class]);

            throw SmsGatewayError::unreachable('connection failed');
        } catch (Throwable $e) {
            Log::warning('Arkesel SMS request failed', ['exception' => $e::class]);

            throw SmsGatewayError::unreachable('request failed');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw SmsGatewayError::malformedResponse();
        }

        // Arkesel answers 200 with status "success", and 4xx/5xx with an
        // explanatory body. Both are failures here, and neither may be
        // reported as a sent code.
        if ($response->failed() || ($body['status'] ?? null) !== 'success') {
            $reason = is_string($body['message'] ?? null) ? $body['message'] : 'unknown error';

            Log::warning('Arkesel rejected an SMS', [
                'http_status' => $response->status(),
                // Deliberately the provider's own short reason only. The
                // request body echoed back would contain the code.
                'message' => $reason,
            ]);

            throw SmsGatewayError::rejected($reason);
        }
    }
}

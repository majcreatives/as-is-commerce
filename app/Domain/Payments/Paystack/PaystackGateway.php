<?php

declare(strict_types=1);

namespace App\Domain\Payments\Paystack;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Payments\ValueObjects\InitializedTransaction;
use App\Domain\Payments\ValueObjects\ProviderRefund;
use App\Domain\Payments\ValueObjects\VerifiedTransaction;
use App\Domain\Shared\Money\Money;
use App\Models\User;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paystack, contained.
 *
 * Every Paystack-specific detail lives here: the endpoints, the request
 * shapes, the response parsing, the signature scheme. Nothing outside this
 * class knows that `data.authorization_url` exists or that amounts go over the
 * wire in subunits.
 *
 * The secret key authenticates server-to-server calls and never leaves the
 * server. TLS verification is never disabled -- an intercepted payment
 * confirmation is a forged payment confirmation.
 */
class PaystackGateway implements PaymentGateway
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $secretKey,
        private readonly string $baseUrl = 'https://api.paystack.co',
        private readonly string $currency = 'GHS',
        private readonly int $timeout = 15,
    ) {}

    public function initializeTransaction(
        User $user,
        Money $amount,
        string $reference,
        string $callbackUrl,
        array $metadata = [],
    ): InitializedTransaction {
        // Paystack expects the amount in the currency's subunit, which is what
        // Money already stores. No conversion, no float.
        $payload = [
            'amount' => $amount->minor,
            'currency' => $amount->currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ];

        // Paystack requires an email. Many Ghanaian customers register with a
        // phone number only, so a routable per-user placeholder is sent rather
        // than blocking the purchase or inventing someone else's address.
        $payload['email'] = $user->email ?? $this->placeholderEmailFor($user);

        $data = $this->post('/transaction/initialize', $payload);

        $authorizationUrl = $data['authorization_url'] ?? null;

        if (! is_string($authorizationUrl) || $authorizationUrl === '') {
            throw PaymentGatewayError::malformedResponse('Paystack');
        }

        return new InitializedTransaction(
            reference: is_string($data['reference'] ?? null) ? $data['reference'] : $reference,
            authorizationUrl: $authorizationUrl,
            accessCode: is_string($data['access_code'] ?? null) ? $data['access_code'] : null,
        );
    }

    public function verifyTransaction(string $reference): VerifiedTransaction
    {
        $data = $this->get('/transaction/verify/'.rawurlencode($reference));

        $amount = $data['amount'] ?? null;

        if (! is_numeric($amount)) {
            throw PaymentGatewayError::malformedResponse('Paystack');
        }

        return new VerifiedTransaction(
            reference: (string) ($data['reference'] ?? $reference),
            status: (string) ($data['status'] ?? 'unknown'),
            amountMinor: (int) $amount,
            currency: strtoupper((string) ($data['currency'] ?? $this->currency)),
            providerTransactionId: isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : null,
            channel: isset($data['channel']) && is_string($data['channel']) ? $data['channel'] : null,
            paidAt: $this->parseTimestamp($data['paid_at'] ?? null),
            // send() already guarantees an array, so no re-check is needed.
            raw: $data,
        );
    }

    /**
     * Ask Paystack to return money against a settled transaction.
     *
     * `POST /refund` takes the transaction by our own reference, so no
     * provider id has to be stored to be able to refund. The amount is
     * optional; omitting it refunds the whole transaction, and supplying it
     * refunds part.
     *
     * WHAT COMES BACK IS NOT A COMPLETED REFUND. Paystack queues refunds and
     * settles them afterwards, so this usually answers `pending`. Reading that
     * as success would mean telling a customer their money is back while
     * Paystack is still deciding whether to send it.
     */
    public function refundTransaction(
        string $reference,
        ?Money $amount = null,
        ?string $reason = null,
    ): ProviderRefund {
        $payload = ['transaction' => $reference];

        if ($amount !== null) {
            // Already integer minor units, which is what Paystack expects.
            // No conversion, no float.
            $payload['amount'] = $amount->minor;
            $payload['currency'] = $amount->currency;
        }

        if ($reason !== null && $reason !== '') {
            // Paystack shows this to nobody the customer would recognise; it
            // is our own filing. Truncated because the field is bounded, and
            // it carries a reason code and an operator's note -- never a
            // credential, a payload or another customer's detail.
            $payload['merchant_note'] = mb_substr($reason, 0, 200);
        }

        return $this->toRefund($this->post('/refund', $payload));
    }

    /**
     * Ask Paystack what became of a refund it accepted.
     *
     * `GET /refund/:id`, where the id is the one Paystack gave us when it
     * accepted the refund. This is the authoritative answer, and the only
     * thing that may move a refund to succeeded.
     */
    public function fetchRefund(string $providerReference): ProviderRefund
    {
        return $this->toRefund($this->get('/refund/'.rawurlencode($providerReference)));
    }

    /**
     * Read Paystack's refund shape into our own.
     *
     * A missing or unreadable status becomes `unknown`, which
     * {@see ProviderRefund} treats as still pending rather than as either
     * outcome. Guessing optimistically here would be the one place a refund
     * could be called successful without evidence.
     *
     * @param  array<string, mixed>  $data
     */
    private function toRefund(array $data): ProviderRefund
    {
        $amount = $data['amount'] ?? null;

        if (! is_numeric($amount)) {
            throw PaymentGatewayError::malformedResponse('Paystack');
        }

        $id = $data['id'] ?? null;

        return new ProviderRefund(
            providerReference: is_scalar($id) && (string) $id !== '' ? (string) $id : null,
            status: is_string($data['status'] ?? null) ? $data['status'] : 'unknown',
            amountMinor: (int) $amount,
            currency: mb_strtoupper((string) ($data['currency'] ?? $this->currency)),
            raw: $data,
        );
    }

    /**
     * Paystack signs webhooks with HMAC SHA512 over the raw body, keyed with
     * the secret, in the `x-paystack-signature` header.
     *
     * The comparison is timing-safe: a plain `===` leaks, through how long it
     * takes to fail, how much of a guessed signature was correct.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if ($signature === null || $signature === '' || $this->secretKey === null || $this->secretKey === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawPayload, $this->secretKey);

        return hash_equals($expected, $signature);
    }

    /**
     * Paystack sends no dedicated event id header, so identity is composed
     * from the event type and the transaction id -- the pair that is stable
     * across a redelivery of the same event.
     *
     * When neither is present, a digest of the payload is used, which is still
     * stable for an identical redelivery.
     *
     * @param  array<string, mixed>  $payload
     */
    public function eventIdentifier(array $payload): string
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : 'unknown';

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $id = $data['id'] ?? null;

        if (is_numeric($id)) {
            return $event.':'.$id;
        }

        $reference = $data['reference'] ?? null;

        if (is_string($reference) && $reference !== '') {
            return $event.':ref:'.$reference;
        }

        return $event.':hash:'.hash('sha256', (string) json_encode($payload));
    }

    // ------------------------------------------------------------ Internals

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->send(fn (PendingRequest $request) => $request->post($this->baseUrl.$path, $payload), $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get($this->baseUrl.$path), $path);
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(Closure $call, string $path): array
    {
        if ($this->secretKey === null || $this->secretKey === '') {
            throw PaymentGatewayError::notConfigured('Paystack');
        }

        try {
            $response = $call(
                $this->http
                    ->withToken($this->secretKey)
                    ->acceptJson()
                    ->asJson()
                    ->timeout($this->timeout)
            );
        } catch (ConnectionException) {
            // Logged without the payload: request bodies carry customer detail
            // and the Authorization header carries the secret.
            Log::warning('Paystack request failed to connect', ['path' => $path]);

            throw PaymentGatewayError::unreachable('Paystack');
        } catch (Throwable $e) {
            Log::warning('Paystack request failed', ['path' => $path, 'exception' => $e::class]);

            throw PaymentGatewayError::unreachable('Paystack');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw PaymentGatewayError::malformedResponse('Paystack');
        }

        if ($response->failed() || ($body['status'] ?? false) !== true) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'unknown error';

            Log::warning('Paystack rejected a request', [
                'path' => $path,
                'http_status' => $response->status(),
                'message' => $message,
            ]);

            throw PaymentGatewayError::rejected('Paystack', $message);
        }

        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw PaymentGatewayError::malformedResponse('Paystack');
        }

        return $data;
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A deterministic, non-routable address for a user without an email.
     *
     * Deterministic so repeated purchases by the same customer group under one
     * Paystack customer record rather than creating a new one each time.
     */
    private function placeholderEmailFor(User $user): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'as-is-commerce.local';

        return "user-{$user->id}@no-email.{$host}";
    }
}

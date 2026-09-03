<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Actions\ProcessWebhookEvent;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Enums\PaymentProvider;
use App\Enums\WebhookProcessingStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Paystack webhooks.
 *
 * Public and unauthenticated, because Paystack cannot log in. The signature is
 * therefore the only thing separating the provider from anyone else who finds
 * the URL, and it is checked before anything is stored, parsed for meaning, or
 * acted on.
 *
 * The endpoint never grants credits on the strength of the payload. It
 * identifies which transaction the event concerns and hands off to a
 * fulfilment path that asks Paystack directly what happened.
 */
final class PaystackWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentGateway $gateway,
        ProcessWebhookEvent $processor,
    ): JsonResponse {
        // The raw body, not the parsed array: re-encoding changes the bytes
        // and would invalidate a signature computed over the original.
        $raw = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $gateway->verifyWebhookSignature($raw, $signature)) {
            // Not stored and not described: an unsigned request is from an
            // unknown party, and telling them why they failed helps them.
            Log::warning('Rejected a webhook with an invalid signature', [
                'provider' => PaymentProvider::Paystack->value,
                'ip' => $request->ip(),
                'has_signature' => $signature !== null,
            ]);

            return response()->json(['status' => 'rejected'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! isset($payload['event']) || ! is_string($payload['event'])) {
            Log::warning('Rejected a signed webhook with an unreadable body', [
                'provider' => PaymentProvider::Paystack->value,
            ]);

            return response()->json(['status' => 'malformed'], 400);
        }

        $event = $this->store($gateway, $payload, $signature);

        // Already stored and handled. Paystack retries on anything that is not
        // a prompt 2xx, so a redelivery is acknowledged rather than reworked.
        if ($event === null) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $status = $processor->handle($event);

        // A failure returns 5xx so the provider retries: the event is stored,
        // so a retry is safe, and a transient fault should not silently strand
        // a paid customer without credits.
        if ($status === WebhookProcessingStatus::Failed) {
            return response()->json(['status' => 'retry'], 500);
        }

        return response()->json(['status' => $status->value], 200);
    }

    /**
     * Store the event, or recognise it as one already seen.
     *
     * The unique index does the recognising, not a preceding SELECT, which
     * would race two simultaneous deliveries of the same event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function store(PaymentGateway $gateway, array $payload, ?string $signature): ?PaymentWebhookEvent
    {
        try {
            return PaymentWebhookEvent::create([
                'provider' => PaymentProvider::Paystack,
                'provider_event_id' => $gateway->eventIdentifier($payload),
                'event_type' => (string) $payload['event'],
                'payload' => $payload,
                'signature' => $signature,
                'processing_status' => WebhookProcessingStatus::Received,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::info('Ignored a repeat webhook delivery', [
                'provider' => PaymentProvider::Paystack->value,
                'event_type' => $payload['event'],
            ]);

            return null;
        }
    }
}

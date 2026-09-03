<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Enums\CreditPurchaseStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\CreditPurchase;
use App\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acts on a webhook event that has already been signature-verified and stored.
 *
 * The event tells us which transaction to look at. It does not tell us what
 * happened to it -- that comes from asking the provider directly, inside
 * {@see FulfillCreditPurchase}. An event body saying `charge.success` is a
 * claim, not evidence, and granting credits on the strength of it would mean
 * trusting whoever produced the request.
 *
 * Every outcome is recorded on the event row, so a failure keeps its payload
 * and can be replayed rather than disappearing.
 */
final class ProcessWebhookEvent
{
    /**
     * Event types that lead to a fulfilment attempt. Anything else is stored
     * and marked ignored rather than silently dropped.
     *
     * @var list<string>
     */
    public const FULFILLING_EVENTS = ['charge.success'];

    public function __construct(
        private readonly FulfillCreditPurchase $fulfil,
        private readonly TransitionCreditPurchase $transition,
    ) {}

    public function handle(PaymentWebhookEvent $event): WebhookProcessingStatus
    {
        if ($event->processing_status === WebhookProcessingStatus::Processed) {
            return WebhookProcessingStatus::Duplicate;
        }

        try {
            $status = $this->dispatch($event);
        } catch (Throwable $e) {
            // Recorded, not swallowed. The stored payload allows a retry, and
            // the message says why it failed without echoing the payload.
            $event->processing_status = WebhookProcessingStatus::Failed;
            $event->processing_error = mb_substr($e->getMessage(), 0, 1000);
            $event->processed_at = now();
            $event->save();

            Log::warning('Webhook processing failed', [
                'event_id' => $event->id,
                'provider' => $event->provider->value,
                'event_type' => $event->event_type,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return WebhookProcessingStatus::Failed;
        }

        $event->processing_status = $status;
        $event->processed_at = now();
        $event->save();

        return $status;
    }

    private function dispatch(PaymentWebhookEvent $event): WebhookProcessingStatus
    {
        if (! in_array($event->event_type, self::FULFILLING_EVENTS, true)) {
            return $this->handleNonFulfilling($event);
        }

        $purchase = $this->resolvePurchase($event);

        if ($purchase === null) {
            // A payment we have no record of. Not an error on our side -- the
            // same Paystack account may serve other integrations -- so it is
            // recorded and ignored rather than retried forever.
            return WebhookProcessingStatus::Ignored;
        }

        $event->credit_purchase_id = $purchase->id;

        $result = $this->fulfil->handle($purchase);

        return $result['already_fulfilled']
            ? WebhookProcessingStatus::Duplicate
            : WebhookProcessingStatus::Processed;
    }

    /**
     * Events that are not a successful charge.
     *
     * A failed charge closes out a purchase that is still awaiting payment. A
     * refund or chargeback is recorded but deliberately does not claw credits
     * back: those credits may already have been spent, and reversing a spend
     * is a business decision rather than something to infer from an event.
     */
    private function handleNonFulfilling(PaymentWebhookEvent $event): WebhookProcessingStatus
    {
        $purchase = $this->resolvePurchase($event);

        if ($purchase === null) {
            return WebhookProcessingStatus::Ignored;
        }

        $event->credit_purchase_id = $purchase->id;

        if ($event->event_type === 'charge.failed' && $purchase->isAwaitingPayment()) {
            $this->transition->handle(
                $purchase,
                CreditPurchaseStatus::Failed,
                'The payment provider reported the charge failed.',
            );

            return WebhookProcessingStatus::Processed;
        }

        Log::info('Webhook event recorded without action', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'purchase_id' => $purchase->id,
            'purchase_status' => $purchase->status->value,
        ]);

        return WebhookProcessingStatus::Ignored;
    }

    /**
     * Find the purchase an event refers to.
     *
     * By reference, which the provider echoes back from what we sent. The
     * metadata id is checked only as a cross-reference: trusting it alone
     * would let a crafted payload point an event at someone else's purchase.
     */
    private function resolvePurchase(PaymentWebhookEvent $event): ?CreditPurchase
    {
        $data = $event->payload['data'] ?? [];

        if (! is_array($data)) {
            return null;
        }

        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $purchase = CreditPurchase::where('provider_reference', $reference)->first();

        if ($purchase === null) {
            return null;
        }

        $claimedId = $data['metadata']['credit_purchase_id'] ?? null;

        if ($claimedId !== null && (int) $claimedId !== $purchase->id) {
            throw PaymentVerificationFailed::because(
                'The event metadata names a different purchase from the one its reference resolves to.'
            );
        }

        return $purchase;
    }
}

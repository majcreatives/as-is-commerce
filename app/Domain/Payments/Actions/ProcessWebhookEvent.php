<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Enums\CreditPurchaseStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\CreditPurchase;
use App\Models\OrderPayment;
use App\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Acts on a webhook event that has already been signature-verified and stored.
 *
 * The event tells us which transaction to look at. It does not tell us what
 * happened to it -- that comes from asking the provider directly, inside the
 * fulfilment path. An event body saying `charge.success` is a claim, not
 * evidence, and handing over credits or a product on the strength of one would
 * mean trusting whoever produced the request.
 *
 * TWO KINDS OF PAYMENT ARRIVE HERE. A reference belongs either to a credit
 * purchase or to an order payment, never both, and this resolves which before
 * doing anything. Order payments are checked first only because they are the
 * newer and larger flow; the two reference formats do not overlap, so the
 * order of the lookups changes nothing.
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
        private readonly FulfillCreditPurchase $fulfilCredits,
        private readonly FulfillOrderPayment $fulfilOrder,
        private readonly TransitionCreditPurchase $transition,
        private readonly OrderLifecycle $orders,
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

    /**
     * Work out what this event is about, then act on it.
     */
    private function dispatch(PaymentWebhookEvent $event): WebhookProcessingStatus
    {
        $reference = $this->reference($event);

        if ($reference === null) {
            return WebhookProcessingStatus::Ignored;
        }

        $payment = OrderPayment::where('provider_reference', $reference)->first();

        if ($payment !== null) {
            $event->order_payment_id = $payment->id;

            return $this->handleOrderPayment($event, $payment);
        }

        $purchase = CreditPurchase::where('provider_reference', $reference)->first();

        if ($purchase === null) {
            // A payment we have no record of. Not an error on our side -- the
            // same Paystack account may serve other integrations -- so it is
            // recorded and ignored rather than retried forever.
            return WebhookProcessingStatus::Ignored;
        }

        $this->assertMetadataAgrees($event, 'credit_purchase_id', $purchase->id);

        $event->credit_purchase_id = $purchase->id;

        return $this->handleCreditPurchase($event, $purchase);
    }

    // ------------------------------------------------------- Order payments

    private function handleOrderPayment(
        PaymentWebhookEvent $event,
        OrderPayment $payment,
    ): WebhookProcessingStatus {
        $this->assertMetadataAgrees($event, 'order_payment_id', $payment->id);

        if (! in_array($event->event_type, self::FULFILLING_EVENTS, true)) {
            return $this->handleFailedOrderCharge($event, $payment);
        }

        // Verification happens inside, against the provider and against this
        // attempt's own frozen amount. Nothing in the payload is trusted.
        $result = $this->fulfilOrder->handle($payment);

        return $result['already_fulfilled']
            ? WebhookProcessingStatus::Duplicate
            : WebhookProcessingStatus::Processed;
    }

    /**
     * Anything that is not a successful charge on an order payment.
     *
     * A failed charge closes out an order that is still awaiting payment,
     * which also releases any unit it was holding. A refund or chargeback is
     * recorded but deliberately does nothing further: reversing a completed
     * sale is a business decision, not something to infer from an event, and
     * refunds are not built in this stage.
     */
    private function handleFailedOrderCharge(
        PaymentWebhookEvent $event,
        OrderPayment $payment,
    ): WebhookProcessingStatus {
        if ($event->event_type === 'charge.failed' && $payment->order->isAwaitingPayment()) {
            $this->orders->markPaymentFailed(
                $payment->order,
                'The payment provider reported the charge failed.',
            );

            return WebhookProcessingStatus::Processed;
        }

        Log::info('Webhook event recorded without action', [
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'order_payment_id' => $payment->id,
            'order_status' => $payment->order->status->value,
        ]);

        return WebhookProcessingStatus::Ignored;
    }

    // ------------------------------------------------------ Credit purchases

    private function handleCreditPurchase(
        PaymentWebhookEvent $event,
        CreditPurchase $purchase,
    ): WebhookProcessingStatus {
        if (! in_array($event->event_type, self::FULFILLING_EVENTS, true)) {
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

        $result = $this->fulfilCredits->handle($purchase);

        return $result['already_fulfilled']
            ? WebhookProcessingStatus::Duplicate
            : WebhookProcessingStatus::Processed;
    }

    // ------------------------------------------------------------ Internals

    /**
     * The provider's reference for this event.
     *
     * The provider echoes back what we sent it, so this is how an event is
     * tied to something of ours.
     */
    private function reference(PaymentWebhookEvent $event): ?string
    {
        $data = $event->payload['data'] ?? [];

        if (! is_array($data)) {
            return null;
        }

        $reference = $data['reference'] ?? null;

        return is_string($reference) && $reference !== '' ? $reference : null;
    }

    /**
     * Cross-check the metadata against what the reference resolved to.
     *
     * The reference decides; the metadata is only ever a corroboration.
     * Trusting the metadata alone would let a crafted payload point an event
     * at somebody else's payment.
     */
    private function assertMetadataAgrees(PaymentWebhookEvent $event, string $key, int $expected): void
    {
        $data = $event->payload['data'] ?? [];

        $claimed = is_array($data) ? ($data['metadata'][$key] ?? null) : null;

        if ($claimed !== null && (int) $claimed !== $expected) {
            throw PaymentVerificationFailed::because(
                "The event metadata names a different {$key} from the one its reference resolves to."
            );
        }
    }
}

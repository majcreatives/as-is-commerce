<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Exceptions\PaymentNotAcceptable;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentProvider;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Open a payment with the provider for an order that is already priced.
 *
 * THE AMOUNT COMES FROM THE ORDER, WHICH WAS FROZEN AT CHECKOUT. The browser
 * cannot supply, suggest or influence it: this action takes an order and
 * nothing else, and the order's total was computed on the server before this
 * was reachable. There is no parameter through which a price could enter.
 *
 * The attempt is written before the provider is contacted, and it keeps its
 * own copy of the amount and currency. That copy is what verification later
 * compares the provider's answer against -- checking against the order would
 * mean checking against a figure that could have moved, which is not a check.
 * A trigger refuses any change to it.
 *
 * A NEW ATTEMPT EACH TIME. A customer who abandons a payment page and returns
 * gets a fresh reference rather than reusing a stale one, so the provider's
 * record and ours agree about which transaction is which. At most one attempt
 * per order ever reaches Success.
 *
 * Reuses the Stage 4 {@see PaymentGateway} abstraction unchanged. There is one
 * Paystack adapter in this application, not two.
 */
final class InitializeOrderPayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderLifecycle $orders,
    ) {}

    public function handle(Order $order): OrderPayment
    {
        $payment = DB::transaction(function () use ($order): OrderPayment {
            $locked = $this->orders->lock($order);

            $this->assertPayable($locked);

            // Any earlier attempt still hanging around is closed out first, so
            // an order never has two open attempts competing to be verified.
            $this->abandonOpenAttempts($locked);

            return $this->createAttempt($locked);
        });

        // Outside the transaction: an HTTP call to a third party has no place
        // holding a database lock open, and a provider that is slow would
        // block every other operation touching this order.
        $transaction = $this->gateway->initializeTransaction(
            user: $order->user,
            // From the attempt, which took it from the frozen order.
            amount: $payment->amount(),
            reference: $payment->provider_reference,
            callbackUrl: route(config('orders.callback_route')),
            metadata: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'order_payment_id' => $payment->id,
                'user_id' => $order->user_id,
                'source' => $order->source->value,
            ],
        );

        $payment->authorization_url = $transaction->authorizationUrl;
        $payment->access_code = $transaction->accessCode;
        $payment->status = OrderPaymentStatus::Pending;
        $payment->save();

        Log::info('Order payment initialized', [
            'operation' => 'order_payment.initialize',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_id' => $payment->id,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'reference' => $payment->provider_reference,
        ]);

        return $payment->fresh();
    }

    /**
     * Refuse to ask for money that should not be asked for.
     */
    private function assertPayable(Order $order): void
    {
        if (! $order->status->acceptsPayment()) {
            throw PaymentNotAcceptable::orderNotPayable($order->status->label());
        }

        if ($order->hasExpired()) {
            // Server time. A checkout past its deadline is no longer payable
            // however recently the customer's page was rendered.
            throw PaymentNotAcceptable::expired();
        }
    }

    /**
     * Close out attempts the customer walked away from.
     */
    private function abandonOpenAttempts(Order $order): void
    {
        $open = OrderPayment::query()
            ->where('order_id', $order->id)
            ->open()
            ->lockForUpdate()
            ->get();

        foreach ($open as $attempt) {
            $attempt->status = OrderPaymentStatus::Abandoned;
            $attempt->failure_reason = 'Superseded by a new payment attempt.';
            $attempt->save();
        }
    }

    private function createAttempt(Order $order): OrderPayment
    {
        $payment = new OrderPayment;

        $payment->order_id = $order->id;
        $payment->provider = PaymentProvider::Paystack;
        $payment->provider_reference = $this->generateReference();
        // Frozen. Verification compares the provider against this, and a
        // trigger refuses to let it change.
        $payment->amount_minor = $order->total_minor;
        $payment->currency = $order->currency;
        $payment->status = OrderPaymentStatus::Initiated;
        $payment->idempotency_key = Str::uuid()->toString();
        $payment->save();

        return $payment;
    }

    /**
     * A reference that is unique, unguessable and carries nothing sensitive.
     *
     * Random rather than sequential: it travels to a third party and appears
     * in URLs, so a predictable one would let anyone enumerate other
     * customers' payments.
     */
    private function generateReference(): string
    {
        return 'AIC-P-'.now()->format('Ymd').'-'.strtoupper(Str::random(16));
    }
}

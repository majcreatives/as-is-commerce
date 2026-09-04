<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Exceptions\PaymentNotAcceptable;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Shared\Idempotency\ConcurrentOperationInProgress;
use App\Http\Controllers\Controller;
use App\Models\OrderPayment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where Paystack sends the customer's browser after paying for a product.
 *
 * THIS IS FOR THE CUSTOMER, NOT A SOURCE OF TRUTH. A browser arriving here
 * proves only that a browser arrived: the customer may have abandoned the
 * payment, pressed back, or edited the URL. So the only thing taken from the
 * request is a reference, and even that is used solely to find a payment the
 * signed-in user owns.
 *
 * Fulfilment then runs through exactly the same verified, idempotent path the
 * webhook uses. This is not a second, weaker way to acquire a product, and a
 * customer refreshing the page cannot produce a second sale.
 *
 * The page never says "paid" because the browser came back. It says what the
 * server established, and mobile money settling asynchronously shows as
 * pending rather than failed -- which is the truth, and far better than
 * claiming a success that has not happened.
 */
final class CheckoutCallbackController extends Controller
{
    public function __invoke(Request $request, FulfillOrderPayment $fulfil): View
    {
        $reference = $request->query('reference');

        if (! is_string($reference) || $reference === '') {
            return view('pages.checkout.callback', ['order' => null, 'state' => 'unknown']);
        }

        // Scoped to the signed-in user: a reference is not a capability, and
        // someone else's must not be viewable by pasting it into the URL.
        $payment = OrderPayment::where('provider_reference', $reference)
            ->whereHas('order', fn ($q) => $q->where('user_id', $request->user()->id))
            ->first();

        if ($payment === null) {
            return view('pages.checkout.callback', ['order' => null, 'state' => 'unknown']);
        }

        $state = $this->resolve($fulfil, $payment);

        return view('pages.checkout.callback', [
            'order' => $payment->order->fresh(),
            'payment' => $payment->fresh(),
            'state' => $state,
        ]);
    }

    /**
     * Ask the server what actually happened.
     */
    private function resolve(FulfillOrderPayment $fulfil, OrderPayment $payment): string
    {
        if ($payment->order->isPaid()) {
            return 'paid';
        }

        try {
            // Whether this call completed the order or found it already
            // complete, the customer's situation is the same.
            $fulfil->handle($payment);

            return 'paid';
        } catch (ConcurrentOperationInProgress) {
            // A webhook is fulfilling this same payment right now. Not an
            // error -- the customer just needs a moment.
            return 'processing';
        } catch (PaymentNotAcceptable $e) {
            // The payment has not succeeded yet, or does not match what was
            // asked for. Either way nothing is handed over. Mobile money
            // commonly lands here briefly on its way to succeeding.
            Log::info('Checkout callback did not confirm payment', [
                'order_payment_id' => $payment->id,
                'reason' => $e->getMessage(),
            ]);

            return 'pending';
        } catch (PaymentGatewayError $e) {
            Log::warning('Checkout callback could not reach the payment provider', [
                'order_payment_id' => $payment->id,
                'reason' => $e->getMessage(),
            ]);

            return 'pending';
        }
    }
}

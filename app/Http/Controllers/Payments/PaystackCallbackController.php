<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\Actions\FulfillCreditPurchase;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Domain\Shared\Idempotency\ConcurrentOperationInProgress;
use App\Http\Controllers\Controller;
use App\Models\CreditPurchase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where Paystack sends the customer's browser after payment.
 *
 * This exists for the customer's benefit, not as a source of truth. A browser
 * arriving here proves only that a browser arrived: the customer may have
 * abandoned the payment, pressed back, or edited the URL. So the only thing
 * taken from the request is the reference, and even that is used solely to
 * look up a purchase the signed-in user owns.
 *
 * Fulfilment then runs through exactly the same verified, idempotent path the
 * webhook uses. The callback is not a second, weaker way to obtain credits,
 * and a customer refreshing this page cannot produce a second grant.
 *
 * Mobile money settles asynchronously, so it is entirely normal to arrive here
 * before the payment has completed. That shows as pending rather than failed.
 */
final class PaystackCallbackController extends Controller
{
    public function __invoke(Request $request, FulfillCreditPurchase $fulfil): View
    {
        $reference = $request->query('reference');

        if (! is_string($reference) || $reference === '') {
            return view('pages.credits.callback', ['purchase' => null, 'state' => 'unknown']);
        }

        // Scoped to the signed-in user: a reference is not a capability, and
        // someone else's must not be viewable by pasting it into the URL.
        $purchase = CreditPurchase::where('provider_reference', $reference)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($purchase === null) {
            return view('pages.credits.callback', ['purchase' => null, 'state' => 'unknown']);
        }

        if ($purchase->isFulfilled()) {
            return view('pages.credits.callback', ['purchase' => $purchase, 'state' => 'fulfilled']);
        }

        try {
            $fulfil->handle($purchase);

            // Fulfilment re-reads the purchase under lock, so the model we
            // hold is stale the moment handle() returns -- refresh it before
            // asking what happened, or a successful grant reads as pending.
            $state = $purchase->refresh()->isFulfilled() ? 'fulfilled' : 'pending';
        } catch (ConcurrentOperationInProgress) {
            // A webhook is fulfilling this same purchase right now. Not an
            // error -- the customer just needs a moment.
            $state = 'processing';
        } catch (PaymentVerificationFailed $e) {
            // The payment has not succeeded yet, or does not match. Either
            // way, no credits. Mobile money commonly lands here briefly.
            Log::info('Callback verification did not confirm payment', [
                'purchase_id' => $purchase->id,
                'reason' => $e->getMessage(),
            ]);

            $state = 'pending';
        } catch (PaymentGatewayError $e) {
            Log::warning('Callback could not reach the payment provider', [
                'purchase_id' => $purchase->id,
                'reason' => $e->getMessage(),
            ]);

            $state = 'pending';
        }

        return view('pages.credits.callback', [
            'purchase' => $purchase->fresh(),
            'state' => $state,
        ]);
    }
}

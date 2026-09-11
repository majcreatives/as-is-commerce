<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Auction\Actions\CompleteBuyNow;
use App\Domain\Auction\Exceptions\BuyNowUnavailable;
use App\Domain\Auction\Exceptions\InvalidAuctionTransition;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\ValueObjects\BuyNowQuote;
use App\Domain\Orders\Contracts\FulfilmentHandoff;
use App\Domain\Orders\Exceptions\PaymentNotAcceptable;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\ValueObjects\VerifiedTransaction;
use App\Domain\Shared\Idempotency\IdempotencyGuard;
use App\Enums\AuctionStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Events\OrderFulfilmentBlocked;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a verified payment into a completed order.
 *
 * THE ONLY PATH. The webhook and the browser callback both arrive here, and
 * neither is a weaker way in: the callback does not get to skip verification
 * because a customer is watching. Two fulfilment implementations would mean
 * two sets of rules about when a product changes hands, and one of them would
 * eventually be wrong.
 *
 * ORDER OF OPERATIONS, and why each step is where it is:
 *
 *   1. Ask the provider server-to-server what happened. A webhook body says
 *      what someone sent us, not what was paid, and a browser returning from
 *      a payment page proves only that a browser arrived.
 *
 *   2. Check the answer against the frozen payment attempt -- status,
 *      reference, currency, amount. Against the attempt, not the order: the
 *      attempt records what the provider was actually asked for. Any mismatch
 *      is a refusal, never "close enough".
 *
 *   3. Run the effect under the idempotency guard, keyed on the attempt, so
 *      five deliveries of the same event produce one fulfilment.
 *
 *   4. Inside one transaction: lock the order, re-check it is not already
 *      paid, mark the payment successful, complete the source-specific work,
 *      then mark the order paid.
 *
 * THE ORDER IS MARKED PAID LAST, after the product has actually changed hands.
 * If anything fails the whole transaction rolls back and the order stays
 * visibly outstanding rather than looking complete -- which is what lets a
 * retry put it right.
 *
 * LOCK ORDER: order, then auction, then product, then wallet. The same
 * sequence as everywhere else, extended by one step at the front.
 *
 * A PAYMENT IS ALWAYS RECORDED, even when nothing can be delivered against it
 * -- the checkout expired, or somebody else took the last unit while the
 * customer was paying. The money is real, so the attempt is marked successful
 * and the order carries a `fulfilment_blocked_reason` into the administrative
 * queue. Never silently cancelled, and never refunded here: what is owed is a
 * decision for a person, and refunds are a later stage.
 *
 * WHAT IT NEVER DOES. It consumes no credits, returns no credits, and touches
 * no wallet. The bid credits were consumed when the bids were placed and are
 * gone; a settlement is paid in cedis and a Buy Now in cedis, and neither has
 * anything further to do with the credit ledger.
 */
final class FulfillOrderPayment
{
    public const OPERATION = 'order_payment.fulfil';

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly IdempotencyGuard $idempotency,
        private readonly OrderLifecycle $orders,
        private readonly AuctionLifecycle $auctions,
        private readonly CompleteBuyNow $buyNow,
        private readonly FulfilmentHandoff $fulfilment,
    ) {}

    /**
     * Verify a payment attempt with the provider and, if it holds up, complete
     * the order.
     *
     * @return array{order_id: int, payment_id: int, already_fulfilled: bool}
     */
    public function handle(OrderPayment $payment): array
    {
        // Already done. Returning rather than throwing means a customer
        // refreshing the return page sees success, not an error.
        if ($payment->isSuccessful() && $payment->order->isPaid()) {
            return [
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'already_fulfilled' => true,
            ];
        }

        $verified = $this->gateway->verifyTransaction($payment->provider_reference);

        $this->assertMatchesAttempt($payment, $verified);

        $result = $this->idempotency->execute(
            operation: self::OPERATION,
            key: $payment->idempotency_key,
            userId: $payment->order->user_id,
            work: fn (): array => $this->complete($payment, $verified),
        );

        $this->announce($result);

        return $result;
    }

    /**
     * Tell the customer, once the money and the goods have both settled.
     *
     * Outside the guard, so outside the transaction. Everything here has
     * already committed; a message that cannot be composed or sent is logged
     * downstream and cannot make a completed payment look like a failure.
     *
     * A replayed delivery reaches this too -- the guard returns the stored
     * result rather than re-running the work -- which is why every notification
     * downstream is keyed on the order and the outcome rather than on the
     * delivery. Five webhooks produce one message.
     *
     * @param  array{order_id: int, payment_id: int, already_fulfilled: bool, became_paid?: bool, blocked_reason?: string|null}  $result
     */
    private function announce(array $result): void
    {
        $order = Order::find($result['order_id']);

        if ($order === null) {
            return;
        }

        if (($result['became_paid'] ?? false) === true) {
            OrderStatusChanged::dispatch($order, OrderStatus::Paid);
        }

        $blocked = $result['blocked_reason'] ?? null;

        if (is_string($blocked) && $blocked !== '') {
            OrderFulfilmentBlocked::dispatch($order, $blocked);
        }
    }

    /**
     * Every check that stands between a provider's answer and a product
     * changing hands.
     *
     * A failure here is never "close enough". A mismatch means either a bug or
     * an attempt to manipulate a payment, and both are reasons to stop.
     */
    private function assertMatchesAttempt(OrderPayment $payment, VerifiedTransaction $verified): void
    {
        if (! $verified->isSuccessful()) {
            throw PaymentNotAcceptable::notSuccessful($verified->status);
        }

        if (! hash_equals($payment->provider_reference, $verified->reference)) {
            throw PaymentNotAcceptable::referenceMismatch(
                $payment->provider_reference,
                $verified->reference,
            );
        }

        if (strtoupper($verified->currency) !== strtoupper($payment->currency)) {
            throw PaymentNotAcceptable::currencyMismatch($payment->currency, $verified->currency);
        }

        // Compared against the attempt's own frozen amount. If the order were
        // repriced after this attempt was opened -- it cannot be, but if it
        // were -- the customer paid what they were asked for, and that is the
        // figure that has to match.
        if ($verified->amountMinor !== $payment->amount_minor) {
            throw PaymentNotAcceptable::amountMismatch($payment->amount_minor, $verified->amountMinor);
        }
    }

    /**
     * @return array{order_id: int, payment_id: int, already_fulfilled: bool}
     */
    private function complete(OrderPayment $payment, VerifiedTransaction $verified): array
    {
        return DB::transaction(function () use ($payment, $verified): array {
            // Locked so two deliveries arriving together cannot both pass the
            // paid check. The guard already serializes on the key; this closes
            // the window inside the transaction as well.
            $order = $this->orders->lock($payment->order);

            if ($order->isPaid()) {
                // A second, distinct payment was verified against an order the
                // first one already paid. The money is real, so it is recorded
                // on this attempt rather than thrown away -- the webhook must
                // be acknowledged, and the record is where a person sees that
                // an extra payment exists and decides what is owed. No second
                // sale happens here, and nothing is refunded automatically.
                $this->recordSuccess($payment, $verified);

                Log::info('Verified payment recorded against an already-paid order', [
                    'operation' => 'order_payment.released_already_paid',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'payment_id' => $payment->id,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                ]);

                return [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'already_fulfilled' => true,
                ];
            }

            if (! $order->status->acceptsPayment()) {
                // The checkout closed while the customer was paying -- it
                // expired, or they cancelled it. The payment still succeeded,
                // so it is recorded rather than thrown away, and the order is
                // flagged for a person.
                //
                // Deliberately not an exception. Throwing here returned a 5xx
                // to the provider, which retried the same delivery forever
                // against an order that could never accept it. Recording the
                // fact and acknowledging the webhook is both truthful and
                // terminal.
                //
                // The status is left as it is. It says what actually happened
                // to this checkout, and moving a cancelled order to Paid would
                // assert the platform accepted an order it had already closed.
                $this->recordSuccess($payment, $verified);

                $this->orders->blockFulfilment(
                    $order,
                    'A payment was verified after this checkout was already '
                    ."[{$order->status->value}], so nothing could be delivered against it.",
                );

                return [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'already_fulfilled' => false,
                    // Not paid: the order kept its terminal status. Only the
                    // block is announced.
                    'became_paid' => false,
                    'blocked_reason' => $order->fulfilment_blocked_reason,
                ];
            }

            $this->recordSuccess($payment, $verified);

            // The product actually changes hands here, before the order is
            // called paid.
            $blocked = $this->deliverGoods($order, $payment);

            $order->paid_at = Carbon::now();
            $this->orders->apply(
                $order,
                OrderStatus::Paid,
                'Payment verified with the provider.',
                null,
            );

            if ($blocked !== null) {
                // Paid, and provably so, but nothing could be handed over.
                // Recorded rather than hidden: it puts the order in a queue
                // for a person, because what is owed to the customer is their
                // decision and is made through the refund workflow.
                $this->orders->blockFulfilment($order, $blocked);
            } else {
                // The unit is genuinely this customer's, so a physical thing
                // now has to reach them. Through the handoff interface rather
                // than a direct call, because the delivery domain already
                // depends on this one.
                //
                // Deliberately not for a blocked order: there is nothing to
                // send, and queueing a package nobody can pack would put work
                // in front of the warehouse that does not exist.
                $this->fulfilment->openFor($order);
            }

            Log::info('Order payment fulfilled', [
                'operation' => 'order_payment.fulfilled',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_id' => $payment->id,
                'source' => $order->source->value,
                'auction_id' => $order->auction_id,
                'amount_minor' => $payment->amount_minor,
                'blocked' => $blocked,
            ]);

            return [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'already_fulfilled' => false,
                'became_paid' => true,
                'blocked_reason' => $blocked,
            ];
        });
    }

    /**
     * Mark the attempt successful, keeping what the provider told us.
     */
    private function recordSuccess(OrderPayment $payment, VerifiedTransaction $verified): void
    {
        $payment->status = OrderPaymentStatus::Success;
        $payment->provider_transaction_id = $verified->providerTransactionId;
        $payment->provider_channel = $verified->channel;
        $payment->paid_at = Carbon::now();
        $payment->save();
    }

    /**
     * Hand the product over, by whichever route this order calls for.
     *
     * Returns null when it worked, or a reason when the money arrived but
     * nothing could be delivered.
     *
     * Three routes, one sale in every case:
     *
     *   Buy Now with an auction   the auction's Buy Now termination sells the
     *                             unit it was holding and ends the auction
     *   Buy Now on its own        this order sells the unit it reserved
     *   Auction win               the auction's settlement sells the unit it
     *                             has been holding since it was published
     */
    private function deliverGoods(Order $order, OrderPayment $payment): ?string
    {
        if ($order->source === OrderSource::AuctionWin) {
            return $this->settleAuction($order);
        }

        if ($order->auction_id !== null) {
            return $this->terminateAuctionByBuyNow($order, $payment);
        }

        // A plain catalog purchase. The unit has been held since checkout;
        // this turns that hold into a sale.
        $this->orders->sellUnit($order, $order->user);

        return null;
    }

    /**
     * A Buy Now that ends an auction.
     *
     * Delegates to the Stage 6 action, which locks the auction, checks it is
     * still available, sells the unit and records the Buy Now ending
     * distinctly -- buyer recorded, winner columns left null, and a database
     * constraint refusing any row that claims both.
     *
     * The frozen quote is handed over so the payment is judged against what
     * the customer agreed to at checkout rather than against a price
     * recomputed now.
     */
    private function terminateAuctionByBuyNow(Order $order, OrderPayment $payment): ?string
    {
        $auction = $order->auction;

        if ($auction === null) {
            return 'The auction this order was for no longer exists.';
        }

        $pricing = $order->pricing();

        $quote = new BuyNowQuote(
            listPrice: $order->subtotal(),
            eligibleCredits: $pricing->discountCredits,
            discount: $order->discount(),
            payable: $pricing->goodsTotal(),
            available: true,
        );

        try {
            $this->buyNow->handle(
                auction: $auction,
                buyer: $order->user,
                // The goods, not the total: delivery and tax are the order's
                // own charges and are not part of what the auction was bought
                // for.
                amountPaid: $pricing->goodsTotal(),
                idempotencyKey: $payment->idempotency_key.':buy-now',
                agreedQuote: $quote,
            );
        } catch (BuyNowUnavailable $e) {
            // Somebody bought it first, or the auction closed while this
            // customer was paying. Their money arrived and cannot buy this.
            return $e->getMessage();
        }

        return null;
    }

    /**
     * An auction winner settling.
     *
     * Delegates to the Stage 6 lifecycle, which locks the auction and sells
     * the unit it has been holding since publication. No credits are consumed
     * and none are returned: the winning bid stays exactly as it was.
     */
    private function settleAuction(Order $order): ?string
    {
        $auction = $order->auction;

        if ($auction === null) {
            return 'The auction this order settles no longer exists.';
        }

        if ($auction->status === AuctionStatus::Settled) {
            // Already settled by an earlier delivery of the same payment. The
            // guard should have caught it, and this is the belt to its braces.
            return null;
        }

        try {
            $this->auctions->settle($auction, $order->user);
        } catch (InvalidAuctionTransition $e) {
            // The auction was forfeited or cancelled while the winner was
            // paying. Same situation as above: real money, nothing to give.
            return $e->getMessage();
        }

        return null;
    }
}

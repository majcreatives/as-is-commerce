<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderSource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fold a pending Shop order back into the customer's cart.
 *
 * THE CART IS THE WORKING SURFACE. A pending catalogue order is a frozen
 * snapshot of the basket, set aside to be paid. While it exists, growing the
 * basket would silently leave items unplaced, so this action calls it back:
 * the order is cancelled (its reservation and Store Wallet commitment released
 * by the order lifecycle) and its lines are restored to the cart, where they
 * remain the customer's to edit and place again.
 *
 * CALLED BEFORE EVERY CART CHANGE AND EVERY PLACEMENT. With nothing pending it
 * does nothing; with an order that has already been paid, cancelled or failed
 * it does nothing; with an order past its payment window the lifecycle expires
 * it and restores the lines through the same single rule. Auction-linked
 * orders never match the query and are never touched: the auction rail owns
 * its own checkout.
 *
 * THE PAYMENT GUARD. A payment attempt opened with the provider is being paid
 * right now. Folding underneath it would strand money or leave a reference
 * pointing at a dead order, so a fold that did not ask to abandon in-flight
 * attempts refuses while one is open ({@see InvalidCheckout::paymentInProgress}).
 * The explicit "Move back to my cart" action abandons them first, then folds --
 * the same abandon-then-act pattern {@see InitializeOrderPayment} uses when a
 * fresh attempt supersedes an earlier one.
 */
final class FoldShopPurchaseToCart
{
    public function __construct(
        private readonly OrderLifecycle $orders,
    ) {}

    /**
     * @param  bool  $abandonAttempts  Whether to abandon open payment attempts
     *                                 first. False refuses the fold while one
     *                                 exists.
     * @return Order|null The order that was folded (cancelled or expired), or
     *                    null when there was nothing to fold.
     */
    public function handle(User $buyer, bool $abandonAttempts = false): ?Order
    {
        return DB::transaction(function () use ($buyer, $abandonAttempts): ?Order {
            $pending = Order::query()
                ->where('user_id', $buyer->id)
                ->where('source', OrderSource::BuyNow)
                ->whereNull('auction_id')
                ->awaitingPayment()
                ->latest('id')
                ->first();

            if ($pending === null) {
                return null;
            }

            $locked = $this->orders->lock($pending);

            // Paid, cancelled, failed, or folded between the lookup and the
            // row lock. Nothing left to fold.
            if (! $locked->status->acceptsPayment()) {
                return null;
            }

            // Past its window but the sweep has not reached it yet. Let the
            // lifecycle expire it -- the same terminal state, and the lines
            // come back to the cart the same way.
            if ($locked->hasExpired()) {
                return $this->orders->expire($locked);
            }

            $this->guardInFlightPayment($locked, $abandonAttempts);

            // Cancelling releases the reservation and the committed Store
            // Wallet value and restores the lines to the cart, all in one
            // transaction.
            $this->orders->cancel($locked, 'Folded back into your cart.', $buyer);

            return $locked->fresh();
        });
    }

    /**
     * An open attempt means money may be on its way to the provider.
     *
     * Only the explicit move-back action may abandon such an attempt (marking
     * it Abandoned first, exactly as opening a new payment does). Every
     * automatic fold refuses instead, so the finance rails never decide to
     * walk away from a payment that was started.
     */
    private function guardInFlightPayment(Order $order, bool $abandonAttempts): void
    {
        $open = $order->payments()
            ->open()
            ->lockForUpdate()
            ->get();

        if ($open->isEmpty()) {
            return;
        }

        if (! $abandonAttempts) {
            throw InvalidCheckout::paymentInProgress();
        }

        foreach ($open as $attempt) {
            $attempt->status = OrderPaymentStatus::Abandoned;
            $attempt->failure_reason = 'Abandoned when the checkout was moved back to the cart.';
            $attempt->save();
        }
    }
}

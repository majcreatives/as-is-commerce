<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Shared\Money\Money;
use App\Domain\StoreWallet\Services\StoreWalletLedgerService;
use App\Enums\OrderSource;
use App\Enums\StoreWalletTransactionType;
use App\Models\Order;
use App\Models\StoreWalletTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * How Store Wallet value takes part in a fixed-price catalogue checkout.
 *
 * WHERE IT MAY BE USED, and nowhere else:
 *
 *     Buy Now, no auction    yes. An ordinary shop purchase.
 *     Buy Now on an auction  no. The auction path already gives this buyer
 *                            the cash value of their consumed credits as a
 *                            price reduction; letting Store Wallet in too
 *                            would be two economic mechanisms on one unit.
 *     Auction settlement     no. Whether Store Wallet may fund a settlement is
 *                            an explicit future business decision, and until
 *                            it is made the answer has to be no rather than
 *                            "whatever the code happens to allow".
 *     Bidding credits        never. Not here, not anywhere.
 *
 * A CHECK constraint on `orders` refuses any row that breaks the first three,
 * so this is a guarantee rather than a convention.
 *
 * THE AMOUNT IS DECIDED HERE, NOT BY THE BROWSER. A customer may ask to apply
 * a figure, and that ask is capped -- server-side, against a balance read
 * server-side -- by their real balance and by the order total. A request for
 * more than either is not an error to shout about, it is simply reduced. What
 * the browser cannot do is cause a larger amount to be applied than the
 * customer has.
 *
 * WHEN THE VALUE ACTUALLY LEAVES THE WALLET: at checkout, not at payment.
 *
 * The alternative -- debiting only once the card payment verifies -- looks
 * tidier and is wrong. Between opening a checkout and paying it, the same
 * value could be committed to a second checkout, and both would then find the
 * balance gone at the moment of payment, after the customer had already paid
 * the reduced cash amount. Taking it up front is the same reasoning that makes
 * a catalogue checkout reserve its unit up front, and it is why the release
 * path below exists.
 */
class StoreWalletCheckout
{
    public function __construct(
        private readonly StoreWalletLedgerService $wallets,
    ) {}

    /**
     * How much Store Wallet value this customer could put toward this total.
     *
     * Never more than they have, and never more than is owed. A null request
     * means "as much as possible", which is the sensible reading of a customer
     * ticking a box rather than typing a figure.
     */
    public function applicable(User $user, Money $total, ?Money $requested = null): Money
    {
        $balance = $this->wallets->balanceFor($user, $total->currency);

        if (! $balance->isPositive()) {
            return Money::zero($total->currency);
        }

        $cap = min($balance->minor, $total->minor);

        if ($requested === null) {
            return Money::fromMinor($cap, $total->currency);
        }

        if ($requested->currency !== $total->currency) {
            throw InvalidCheckout::because('Store Wallet value cannot be applied across currencies.');
        }

        if (! $requested->isPositive()) {
            return Money::zero($total->currency);
        }

        // Capped, not refused. A stale page offering more than the balance now
        // holds should apply what is there rather than fail the checkout.
        return Money::fromMinor(min($requested->minor, $cap), $total->currency);
    }

    /**
     * Refuse anything this order is not allowed to do with Store Wallet.
     *
     * Called before the order is written, so a refusal costs nothing.
     */
    public function assertEligible(OrderSource $source, ?int $auctionId, Money $applied, Money $total): void
    {
        if (! $applied->isPositive()) {
            return;
        }

        if ($source !== OrderSource::BuyNow || $auctionId !== null) {
            throw InvalidCheckout::because(
                'Store Wallet credit can only be used on fixed-price catalogue purchases.'
            );
        }

        if ($applied->minor > $total->minor) {
            throw InvalidCheckout::because('Store Wallet credit cannot exceed what is owed.');
        }

        // The boundary of this stage, stated as a refusal rather than left to
        // be discovered. An order with nothing left to pay would need a way to
        // become Paid without a provider verifying anything, and there is
        // deliberately no such path in this application.
        if ($applied->minor === $total->minor) {
            throw InvalidCheckout::because(
                'Store Wallet credit cannot cover an order in full yet: a part of every order '
                .'must still be paid so the payment can be verified with the provider.'
            );
        }
    }

    /**
     * Take the value out of the wallet and commit it to this order.
     *
     * Called inside the checkout transaction, after the order row exists, so
     * the ledger entry can reference it. If anything later in that transaction
     * fails, this rolls back with it and the value was never committed.
     */
    public function commit(Order $order, Money $amount, User $buyer): ?StoreWalletTransaction
    {
        if (! $amount->isPositive()) {
            return null;
        }

        $transaction = $this->wallets->debit(
            wallet: $this->wallets->walletFor($buyer, $amount->currency),
            type: StoreWalletTransactionType::OrderApplied,
            amount: $amount,
            reference: $order,
            description: "Applied to order {$order->order_number}.",
            metadata: ['order_id' => $order->id, 'order_number' => $order->order_number],
            actor: $buyer,
            idempotencyKey: self::appliedKeyFor($order),
        );

        Log::info('Store Wallet applied to a checkout', [
            'operation' => 'store_wallet.order_applied',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $buyer->id,
            'amount_minor' => $amount->minor,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }

    /**
     * Give the value back, because the checkout closed without being paid.
     *
     * The mirror of the inventory reservation being released by the same
     * events. An order that expired or was cancelled took value out of a
     * wallet and delivered nothing for it, and leaving that debit standing
     * would quietly consume a customer's balance for a purchase that never
     * happened.
     *
     * Idempotent: a second call finds the release already recorded and does
     * nothing. Two sweeps expiring the same checkout therefore return the
     * value once.
     *
     * NEVER CALLED FOR A PAID ORDER. The value bought something. Whether a
     * refund of a paid order should also return its Store Wallet portion is an
     * unresolved business decision, and is deliberately not decided here.
     */
    public function release(Order $order, string $reason): ?StoreWalletTransaction
    {
        if ($order->store_wallet_applied_minor <= 0) {
            return null;
        }

        if ($order->isPaid()) {
            return null;
        }

        $key = self::releasedKeyFor($order);

        if ($this->wallets->alreadyRecorded($key)) {
            return null;
        }

        $amount = Money::fromMinor($order->store_wallet_applied_minor, $order->currency);

        $transaction = $this->wallets->credit(
            wallet: $this->wallets->walletFor($order->user, $order->currency),
            type: StoreWalletTransactionType::OrderReleased,
            amount: $amount,
            reference: $order,
            description: "Returned from order {$order->order_number}: {$reason}",
            metadata: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reason' => $reason,
            ],
            idempotencyKey: $key,
        );

        Log::info('Store Wallet returned from a closed checkout', [
            'operation' => 'store_wallet.order_released',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'user_id' => $order->user_id,
            'amount_minor' => $amount->minor,
            'reason' => $reason,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }

    /**
     * The debit recorded against this order, if there is one.
     */
    public function appliedTo(Order $order): ?StoreWalletTransaction
    {
        return StoreWalletTransaction::where('idempotency_key', self::appliedKeyFor($order))->first();
    }

    public static function appliedKeyFor(Order $order): string
    {
        return "order-applied:{$order->getKey()}";
    }

    public static function releasedKeyFor(Order $order): string
    {
        return "order-released:{$order->getKey()}";
    }
}

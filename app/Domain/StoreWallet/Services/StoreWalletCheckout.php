<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\Services;

use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderSource;
use App\Enums\StoreWalletTransactionType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The part of the fixed-price checkout that lives in the Store Wallet.
 *
 * WHAT CAN BE PAID WITH STORE WALLET VALUE, AND WHAT CANNOT. Only an ordinary
 * Buy Now catalogue purchase -- one with no auction behind it. The same
 * eligibility is enforced two more times, deliberately: `assertEligible` keeps
 * an invalid amount from ever reaching an order, and a database CHECK refuses
 * the row that would hold it. An auction-linked Buy Now is excluded because the
 * auction is selling a unit it already reserved, and because its price carries
 * the bidder's credit discount; a settlement is excluded by definition.
 *
 * AN ORDER IS NEVER FULLY COVERED. `payable = total - applied` must stay
 * positive, because marking an order paid still requires a verified provider
 * payment, and a fully-covered order would have nothing left for the provider
 * to verify. This is a stated stage boundary, made once here, made again in the
 * pricing value object, and enforced a third time by the database.
 *
 * COMMIT AT CHECKOUT, RELEASE WHEN IT CLOSES. The value is debited when the
 * order is written, not when it is paid: two checkouts must not both plan to
 * spend the same value. If the order closes without a Verified payment it is
 * released by `release()`, which `OrderLifecycle` calls on every path that
 * stops a checkout. Both movements are idempotent by a database-unique key, so
 * a duplicated call cannot apply or return the value twice.
 *
 * LOCK ORDER. The wallet row is locked before its balance is read, inside the
 * debit. The caller holds the order row (and, on an auction path, the auction)
 * already, so the sequence stays: order, auction, product, wallet.
 */
class StoreWalletCheckout
{
    public function __construct(
        private readonly StoreWalletLedgerService $ledger,
    ) {}

    /**
     * How much of a bill this buyer's Store Wallet may cover.
     *
     * The answer is the whole balance, capped at the total -- an order can
     * never have more value applied than it costs. `requested` lets a caller
     * offer a smaller figure; it is not a balance the browser invented, and it
     * is clamped by the same cap.
     */
    public function applicable(User $buyer, Money $total, ?Money $requested = null): Money
    {
        if (! $total->isPositive()) {
            return Money::zero($total->currency);
        }

        $balance = $this->ledger->balanceFor($buyer, $total->currency);

        $capped = $total->minor;
        if ($requested !== null && $requested->isPositive() && $requested->minor < $capped) {
            $capped = $requested->minor;
        }
        if ($balance->minor < $capped) {
            $capped = $balance->minor;
        }

        if ($capped <= 0) {
            return Money::zero($total->currency);
        }

        return Money::fromMinor($capped, $total->currency);
    }

    /**
     * Refuse Store Wallet value where the stage has decided it may not go.
     *
     * Only meaningful when the applied amount is positive -- a zero amount is
     * an empty claim and passes anything. This is the friendly pre-check; the
     * database CHECK is what actually binds.
     */
    public function assertEligible(
        OrderSource $source,
        ?int $auctionId,
        Money $applied,
        Money $total,
    ): void {
        if (! $applied->isPositive()) {
            return;
        }

        if ($source !== OrderSource::BuyNow) {
            throw InvalidCheckout::because(
                'Store Wallet value can only be applied to an ordinary Buy Now purchase.'
            );
        }

        if ($auctionId !== null) {
            throw InvalidCheckout::because(
                'Store Wallet value cannot be applied when the purchase ends an auction.'
            );
        }

        if ($applied->minor >= $total->minor) {
            throw InvalidCheckout::because(
                'Store Wallet value cannot cover an order in full: an order still needs a '
                .'verified payment for the part it covers.'
            );
        }
    }

    /**
     * Take the agreed value out of the wallet, keyed to the order.
     *
     * Runs inside the caller's checkout transaction. Repeated calls for the
     * same order reuse the first debit: the key is unique on the ledger table,
     * so a retry can never spend the value twice.
     */
    public function commit(Order $order, User $buyer, Money $amount): void
    {
        if (! $amount->isPositive()) {
            return;
        }

        $wallet = $this->ledger->walletFor($buyer, $amount->currency);

        DB::transaction(function () use ($order, $wallet, $amount): void {
            $this->ledger->debit(
                wallet: $wallet,
                type: StoreWalletTransactionType::OrderApplied,
                amount: $amount,
                reference: $order,
                description: "Value applied to order {$order->order_number}.",
                idempotencyKey: self::appliedKeyFor($order->id),
            );
        });

        Log::info('Store Wallet value committed to an order', [
            'operation' => 'store_wallet.commit',
            'order_id' => $order->id,
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'amount_minor' => $amount->minor,
        ]);
    }

    /**
     * Give committed value back because the checkout closed unpaid.
     *
     * Runs on every path that stops a checkout before it is paid -- cancelled,
     * expired, payment failed. It is never run for a paid order: that value
     * paid for something, and whether a refund should return it is an
     * unresolved business decision, not one this method decides by returning
     * money it cannot honestly be owed.
     *
     * Idempotent: releasing an order that has already been released (or whose
     * value was never committed -- a zero applied amount) changes nothing, as
     * does releasing a paid order. The release key is unique on the ledger
     * table, so a duplicated call cannot return the value twice.
     */
    public function release(Order $order): void
    {
        if ($order->isPaid()) {
            return;
        }

        $applied = $order->storeWalletApplied();

        if (! $applied->isPositive()) {
            return;
        }

        $key = self::releasedKeyFor($order->id);

        if ($this->ledger->alreadyRecorded($key)) {
            return;
        }

        $wallet = $this->ledger->walletFor($order->user, $applied->currency);

        DB::transaction(function () use ($order, $wallet, $applied, $key): void {
            $this->ledger->credit(
                wallet: $wallet,
                type: StoreWalletTransactionType::OrderReleased,
                amount: $applied,
                reference: $order,
                description: "Value returned from unpaid order {$order->order_number}.",
                idempotencyKey: $key,
                actor: $order->user,
            );
        });

        Log::info('Store Wallet value released from a closed order', [
            'operation' => 'store_wallet.release',
            'order_id' => $order->id,
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'amount_minor' => $applied->minor,
        ]);
    }

    /**
     * The key that makes an application happen exactly once. Order-scoped, not
     * amount-scoped, so a retry cannot stack two spends on the same order.
     */
    public static function appliedKeyFor(int $orderId): string
    {
        return 'store-wallet:applied:order:'.$orderId;
    }

    /**
     * The key that makes a release happen exactly once.
     */
    public static function releasedKeyFor(int $orderId): string
    {
        return 'store-wallet:released:order:'.$orderId;
    }
}

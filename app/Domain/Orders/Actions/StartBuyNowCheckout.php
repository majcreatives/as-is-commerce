<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Services\CheckoutPricer;
use App\Domain\Orders\ValueObjects\CheckoutPricing;
use App\Domain\StoreWallet\Services\StoreWalletCheckout;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Auction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTransition;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Open a checkout to buy a product outright.
 *
 * WHAT THE CUSTOMER GETS TO DECIDE: which product, or which auction. That is
 * the whole of it. Every figure -- the price, the discount their consumed bid
 * credits earn, delivery, tax, the total -- is computed here from server-side
 * reads and frozen onto the order before the provider is ever contacted.
 *
 * OPENING A CHECKOUT DOES NOT END AN AUCTION. It creates an obligation to pay
 * and nothing more. The auction runs on, other people keep bidding, and the
 * standing highest bidder is still in the running. Only a verified payment
 * ends it. Terminating here would let an abandoned checkout kill a live
 * auction that other people were still competing in.
 *
 * THE RESERVATION, AND WHY ONLY SOMETIMES. An auction-linked checkout holds
 * nothing, because the auction reserved that unit when it was published and it
 * is the same unit being bought. Reserving again would take two units off the
 * shelf for one sale. A plain catalogue purchase holds a unit aside while the
 * customer pays -- but in this stage that rail runs through the cart: the
 * catalogue path below delegates to {@see PlaceCartOrder}, which builds a
 * single-line, quantity-one cart order, reserves its unit atomically and
 * applies the one-pending-order rule. This action's own reservation logic
 * serves the auction path alone.
 *
 * The order is created before the provider is contacted, so a payment that
 * succeeds at Paystack but fails on the way back to us still has a row to be
 * reconciled against. The reverse order would lose the payment entirely.
 */
final class StartBuyNowCheckout
{
    public function __construct(
        private readonly CheckoutPricer $pricer,
        private readonly AuctionLifecycle $auctions,
        private readonly StoreWalletCheckout $storeWallet,
        private readonly AddToCart $addToCart,
        private readonly PlaceCartOrder $placeCart,
    ) {}

    /**
     * @param  Auction|null  $auction  The auction being bought out of, when
     *                                 this purchase would end one.
     */
    public function handle(User $buyer, Product $product, ?Auction $auction = null): Order
    {
        // No auction behind it: an ordinary catalogue purchase, which in this
        // stage is made through the cart so there is one checkout rail. One
        // line, one unit, placed atomically (reservation + one-pending-order
        // guard + pricing + Store Wallet all inside PlaceCartOrder).
        if ($auction === null) {
            $this->addToCart->handle($buyer, $product, 1);

            return $this->placeCart->handle($buyer);
        }

        return DB::transaction(function () use ($buyer, $product, $auction): Order {
            // Locked first, so the eligibility check and the discount are
            // decided against state nobody else can change until this commits.
            // Same order as everywhere else: auction, then product.
            $lockedAuction = $this->auctions->lock($auction);

            $this->assertBuyable($product, $lockedAuction);
            $this->assertNoOpenCheckout($buyer, $product, $lockedAuction);

            $pricing = $this->pricer->forBuyNow($product, $buyer, $lockedAuction);

            $order = $this->createOrder(
                buyer: $buyer,
                product: $product,
                pricing: $pricing,
                auction: $lockedAuction,
                dueAt: $this->deadlineFor($lockedAuction),
            );

            // An auction-linked checkout holds nothing: the auction already
            // holds the unit this would buy, and reserving again would take
            // two units off the shelf for one sale. Store Wallet value was part
            // of the bill; take it at checkout so two checkouts cannot both
            // plan to spend it. Idempotent by key.
            //
            // Only a plain catalogue purchase may carry any, and catalogue
            // purchases take the cart rail, so this path always locks the
            // product before the wallet -- the same order used everywhere else
            // in the stage, so there is no inverted lock order to deadlock
            // against the completion path, which locks product then wallet.
            $this->storeWallet->commit($order, $buyer, $pricing->storeWalletApplied);

            Log::info('Buy Now checkout opened', [
                'operation' => 'checkout.buy_now',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $buyer->id,
                'product_id' => $product->id,
                'auction_id' => $lockedAuction->id,
                // Stated together so the log shows the two are different
                // quantities: cedis off, and the credits that earned them.
                'discount_minor' => $pricing->discount->minor,
                'discount_credits' => $pricing->discountCredits,
                'total_minor' => $pricing->total->minor,
                // The Store Wallet portion and what the provider will verify
                // are separate figures that must add up to the total.
                'store_wallet_applied_minor' => $pricing->storeWalletApplied->minor,
                'payable_minor' => $pricing->payable->minor,
                'payment_due_at' => $order->payment_due_at?->toIso8601String(),
            ]);

            return $order->fresh();
        });
    }

    /**
     * Everything that must be true before a customer is asked for money.
     */
    private function assertBuyable(Product $product, ?Auction $auction): void
    {
        if ($auction !== null) {
            if ($auction->product_id !== $product->id) {
                throw InvalidCheckout::because('That auction is not for this product.');
            }

            if (! $auction->rules()->buyNowEnabled) {
                throw InvalidCheckout::because('Buy Now is not available on this auction.');
            }

            if ($auction->endedByBuyNow()) {
                throw InvalidCheckout::because('This product has already been bought outright.');
            }

            if (! $auction->status->acceptsBuyNow()) {
                throw InvalidCheckout::because('This auction is not open.');
            }

            // The auction is holding the unit, so available stock is zero by
            // design. What matters is that the unit still exists.
            if ($product->stock_on_hand < 1) {
                throw InvalidCheckout::notPurchasable($product->name);
            }

            return;
        }

        // A plain catalog purchase has to satisfy the catalog's own rules:
        // active, and with something actually available to reserve.
        if (! $product->isPurchasable()) {
            throw InvalidCheckout::notPurchasable($product->name);
        }
    }

    /**
     * One open checkout per customer per thing.
     *
     * Without this a customer could open ten checkouts on the last unit and
     * hold the whole stock hostage while paying for one of them.
     */
    private function assertNoOpenCheckout(User $buyer, Product $product, ?Auction $auction): void
    {
        $existing = Order::query()
            ->where('user_id', $buyer->id)
            ->where('source', OrderSource::BuyNow)
            ->awaitingPayment()
            ->when(
                $auction === null,
                fn ($q) => $q->whereNull('auction_id')
                    ->whereHas('items', fn ($i) => $i->where('product_id', $product->id)),
                fn ($q) => $q->where('auction_id', $auction->id),
            )
            ->first();

        if ($existing !== null && ! $existing->hasExpired()) {
            throw InvalidCheckout::alreadyOpen($existing->order_number);
        }
    }

    /**
     * When this checkout stops being payable.
     *
     * From the auction's own frozen deadline when there is one, so the terms a
     * bidder saw are the terms that apply. Otherwise from a setting an
     * administrator owns. Server time in both cases.
     */
    private function deadlineFor(?Auction $auction): Carbon
    {
        $minutes = $auction !== null
            ? $auction->rules()->checkoutDeadlineMinutes
            : (settings()->getInt('checkout_hold_minutes', 30) ?? 30);

        return Carbon::now()->addMinutes(max(1, $minutes));
    }

    /**
     * Write the order, its single line, and its opening history entry.
     */
    private function createOrder(
        User $buyer,
        Product $product,
        CheckoutPricing $pricing,
        ?Auction $auction,
        Carbon $dueAt,
    ): Order {
        $order = new Order;

        $order->order_number = $this->generateOrderNumber();
        $order->user_id = $buyer->id;
        $order->source = OrderSource::BuyNow;
        $order->status = OrderStatus::PendingPayment;
        $order->auction_id = $auction?->id;

        $order->currency = $pricing->currency();
        $order->subtotal_minor = $pricing->subtotal->minor;
        $order->discount_minor = $pricing->discount->minor;
        $order->delivery_minor = $pricing->delivery->minor;
        $order->tax_minor = $pricing->tax->minor;
        $order->total_minor = $pricing->total->minor;
        $order->discount_credits = $pricing->discountCredits;
        // The portion of the total already covered by Store Wallet value, and
        // the remainder the provider will be asked to verify. The two are
        // loaded from the value object so they always add up to the total.
        $order->store_wallet_applied_minor = $pricing->storeWalletApplied->minor;
        $order->payable_minor = $pricing->payable->minor;
        $order->pricing_snapshot = $pricing->toArray();

        $order->payment_due_at = $dueAt;
        $order->placed_at = Carbon::now();
        $order->save();

        $item = new OrderItem;
        $item->order_id = $order->id;
        $item->product_id = $product->id;
        // Snapshots: this order must still describe itself when the product
        // has been renamed, re-SKU'd or repriced.
        $item->product_name_snapshot = $product->name;
        $item->sku_snapshot = $product->sku;
        $item->quantity = 1;
        $item->unit_price_minor = $pricing->subtotal->minor;
        $item->discount_minor = $pricing->discount->minor;
        $item->line_total_minor = $pricing->goodsTotal()->minor;
        $item->save();

        OrderTransition::create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderStatus::PendingPayment,
            'reason' => $auction === null
                ? 'Buy Now checkout opened.'
                : "Buy Now checkout opened on auction #{$auction->id}.",
            'caused_by' => $buyer->id,
        ]);

        return $order;
    }

    /**
     * A number that is unique, readable and unguessable.
     *
     * Random rather than sequential: an order number is quoted in emails and
     * URLs, and a predictable one would let anyone enumerate other customers'
     * orders.
     */
    private function generateOrderNumber(): string
    {
        return 'AIC-O-'.now()->format('Ymd').'-'.strtoupper(Str::random(10));
    }
}

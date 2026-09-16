<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Services\CheckoutPricer;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\Orders\ValueObjects\CheckoutPricing;
use App\Domain\StoreWallet\Services\StoreWalletCheckout;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTransition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turn a customer's cart into an order, atomically and all-or-nothing.
 *
 * THIS IS WHERE THE CART BECOMES COMMERCE. Editing a cart reserves nothing and
 * records nothing; this action is the single moment intent becomes an order.
 * Everything happens in one transaction, so if any one line cannot be
 * satisfied the whole placement fails -- no partial order, no reservation left
 * behind, no wallet movement -- and the cart stays intact for the customer to
 * edit and retry. That is the agreed trade-off for a cart that holds no stock
 * (§10 of the cart scope).
 *
 * WHAT THE SERVER DECIDES, NOT THE BROWSER. Which line holds what product and
 * how many comes from the cart rows; every price, the delivery, the tax, the
 * Store Wallet portion and the payable come from server-side reads and are
 * frozen onto the order here. A request carries no amount.
 *
 * ONE PENDING ORDER PER CUSTOMER. A customer cannot place a new cart order
 * while an earlier catalogue order is awaiting payment (a multi-line order may
 * hold many product lines and quantities). An auction-linked checkout stays
 * allowed alongside one: the auction rail owns its own unit.
 *
 * LOCKING ORDER PRESERVED. The order row is new and needs no lock. Lines are
 * processed in ascending product-id order (`OrderLifecycle::reserveUnit`
 * locks each product row before re-reading availability under the lock, and a
 * short line throws, rolling back every reservation already taken), then the
 * wallet -- the product,wallets order used everywhere else. Two concurrent
 * cart placements lock the same product rows in the same sequence and cannot
 * deadlock each other.
 */
final class PlaceCartOrder
{
    public function __construct(
        private readonly CheckoutPricer $pricer,
        private readonly OrderLifecycle $orders,
        private readonly StoreWalletCheckout $storeWallet,
        private readonly ProductDiscoveryQuery $products,
    ) {}

    public function handle(User $buyer): Order
    {
        return DB::transaction(function () use ($buyer): Order {
            $cart = Cart::query()->forUser($buyer)->lockForUpdate()->first();

            if ($cart === null) {
                throw InvalidCheckout::because('You have no cart to place an order from.');
            }

            $lines = $cart->items()
                ->orderBy('product_id')
                ->with('product')
                ->get();

            if ($lines->isEmpty()) {
                throw InvalidCheckout::because('Your cart is empty.');
            }

            $this->assertNoOpenCatalogueCheckout($buyer);

            foreach ($lines as $line) {
                $this->assertLine($line);
            }

            $pricing = $this->pricer->forCart(
                $lines
                    ->map(fn (CartItem $line): array => [
                        'product' => $line->product,
                        'quantity' => $line->quantity,
                    ])
                    ->values()
                    ->all(),
                $buyer,
            );

            $order = $this->createOrder($buyer, $lines, $pricing);

            // Locks each product row in ascending product-id order and
            // re-reads availability under the lock. If any line cannot
            // satisfy its quantity, the exception rolls back the whole
            // placement -- every reservation already taken goes with it,
            // leaving neither order nor reservation behind.
            $this->orders->reserveUnit($order, $buyer);

            // Store Wallet value was part of the bill; take it at placement so
            // two orders cannot both plan to spend the same value. Idempotent
            // by key. LOCKED AFTER THE PRODUCT ROWS, preserving the order used
            // everywhere else in the stage.
            $this->storeWallet->commit($order, $buyer, $pricing->storeWalletApplied);

            $cart->items()->delete();
            $cart->delete();

            Log::info('Cart order placed', [
                'operation' => 'order.cart_placed',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $buyer->id,
                'lines' => $lines->count(),
                'total_minor' => $pricing->total->minor,
                'store_wallet_applied_minor' => $pricing->storeWalletApplied->minor,
                'payable_minor' => $pricing->payable->minor,
                'payment_due_at' => $order->payment_due_at?->toIso8601String(),
            ]);

            return $order->fresh();
        });
    }

    /**
     * Every line the customer sees must still be true at placement: the
     * product is purchasable, it is not currently held by an active auction
     * (auction-first), and the line wants at least one unit.
     */
    private function assertLine(CartItem $line): void
    {
        $product = $line->product;

        if ($line->quantity < 1) {
            throw InvalidCheckout::because('A cart line must hold at least one unit.');
        }

        if (! $product->isPurchasable()) {
            throw InvalidCheckout::notPurchasable($product->name);
        }

        if ($this->products->activeAuctionFor($product) !== null) {
            throw InvalidCheckout::because(
                "[{$product->name}] is being sold through an auction right now. "
                .'Remove it from your cart and use the auction listing until it resolves.'
            );
        }
    }

    /**
     * One open catalogue checkout per customer.
     *
     * Stricter than four checkouts on four products: a customer may hold only
     * one awaiting-payment cart order at a time. An existing order that has
     * expired no longer blocks a new placement.
     */
    private function assertNoOpenCatalogueCheckout(User $buyer): void
    {
        $existing = Order::query()
            ->where('user_id', $buyer->id)
            ->where('source', OrderSource::BuyNow)
            ->awaitingPayment()
            ->whereNull('auction_id')
            ->first();

        if ($existing !== null && ! $existing->hasExpired()) {
            throw InvalidCheckout::alreadyOpen($existing->order_number);
        }
    }

    /**
     * Write the order, one line per cart line, and its opening history entry.
     *
     * @param  Collection<int, CartItem>  $lines
     */
    private function createOrder(
        User $buyer,
        Collection $lines,
        CheckoutPricing $pricing,
    ): Order {
        $order = new Order;

        $order->order_number = $this->generateOrderNumber();
        $order->user_id = $buyer->id;
        $order->source = OrderSource::BuyNow;
        $order->status = OrderStatus::PendingPayment;
        $order->auction_id = null;

        $order->currency = $pricing->currency();
        $order->subtotal_minor = $pricing->subtotal->minor;
        $order->discount_minor = 0;
        $order->delivery_minor = $pricing->delivery->minor;
        $order->tax_minor = $pricing->tax->minor;
        $order->total_minor = $pricing->total->minor;
        $order->discount_credits = 0;
        $order->store_wallet_applied_minor = $pricing->storeWalletApplied->minor;
        $order->payable_minor = $pricing->payable->minor;
        $order->pricing_snapshot = $pricing->toArray();

        $order->payment_due_at = $this->deadlineFor();
        $order->placed_at = Carbon::now();
        $order->save();

        foreach ($lines as $line) {
            $product = $line->product;
            $unitPrice = $product->buyNowPrice();

            $item = new OrderItem;
            $item->order_id = $order->id;
            $item->product_id = $product->id;
            // Snapshots: this order must still describe itself when the
            // product has been renamed, re-SKU'd or repriced.
            $item->product_name_snapshot = $product->name;
            $item->sku_snapshot = $product->sku;
            $item->quantity = $line->quantity;
            $item->unit_price_minor = $unitPrice->minor;
            $item->discount_minor = 0;
            // No per-line discount on a pure catalogue order, so the line is
            // quantity times unit price, in integer minor units.
            $item->line_total_minor = $unitPrice->minor * $line->quantity;
            $item->save();
        }

        OrderTransition::create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderStatus::PendingPayment,
            'reason' => 'Cart order placed.',
            'caused_by' => $buyer->id,
        ]);

        return $order;
    }

    /**
     * When this checkout stops being payable.
     *
     * From a setting an administrator owns, in server time.
     */
    private function deadlineFor(): Carbon
    {
        $minutes = settings()->getInt('checkout_hold_minutes', 30) ?? 30;

        return Carbon::now()->addMinutes(max(1, $minutes));
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

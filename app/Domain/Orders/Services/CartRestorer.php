<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;

/**
 * Put an order back where it came from.
 *
 * A catalogue order is a frozen snapshot of the basket. When it dies without
 * ever being paid -- cancelled, expired, or the provider reported the charge
 * failed -- the units it was holding go back on sale and its lines go back to
 * the customer's cart. The customer never finished the purchase, and their
 * basket should not vanish with a product they did not buy.
 *
 * THE CART IS INTENT, NOT A RECORD. Restoring lines offers the items again
 * for the customer to keep, edit or place anew. It creates no reservation and
 * no ledger entry, and it touches no money. Callers hold the order row lock
 * and run in the same transaction as the release that gives the reservation
 * and the Store Wallet commitment back, so a restored line can never appear
 * while the order still claims its stock.
 */
final class CartRestorer
{
    /**
     * Bring every line of a Shop order back onto the customer's cart.
     *
     * Lines accumulate in quantity, so a fold that interrupted a basket
     * already holding some of the same product keeps both amounts. A line
     * whose product has gone is skipped -- there is nothing left to restore,
     * and resurrecting a row with no product would break the cart render.
     */
    public function restore(Order $order): void
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $order->user_id]);

        foreach ($order->items()->with('product')->orderBy('product_id')->get() as $item) {
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $line = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            CartItem::query()->updateOrCreate(
                ['cart_id' => $cart->id, 'product_id' => $product->id],
                ['quantity' => ($line->quantity ?? 0) + $item->quantity],
            );
        }
    }
}

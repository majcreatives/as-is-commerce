<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\InvalidCart;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Put a product on a customer's cart.
 *
 * THE CART IS NOT THE ORDER. Adding a line creates no financial record, no
 * ledger entry and no inventory reservation -- it records intent, so a
 * customer can assemble a basket and review it before paying once. The server
 * keeps the cart (one per customer) and the placement action validates every
 * line against the inventory ledger when the order is placed.
 *
 * WHAT THE SERVER DECIDES HERE. The product must be purchasable and must not
 * currently be held by an active auction (auction-first: a product with an
 * auction in flight is reached through that auction, not through the cart).
 * The quantity is capped at what the ledger says is actually available, and
 * the browser's figure is only ever a "how many I want" -- never a price or a
 * total. A line's real price is re-read from the product row at placement.
 */
final class AddToCart
{
    public function __construct(
        private readonly ProductDiscoveryQuery $products,
    ) {}

    public function handle(User $buyer, Product $product, int $quantity = 1): Cart
    {
        if ($quantity < 1) {
            throw InvalidCart::because('A cart line must hold at least one unit.');
        }

        return DB::transaction(function () use ($buyer, $product, $quantity): Cart {
            $product->refresh();

            if (! $product->isPurchasable()) {
                throw InvalidCheckout::notPurchasable($product->name);
            }

            if ($this->products->activeAuctionFor($product) !== null) {
                throw InvalidCart::because(
                    "[{$product->name}] is being sold through an auction right now. "
                    .'Use the auction listing until it resolves.'
                );
            }

            $available = $product->availableStock();

            if ($available < 1) {
                throw InvalidCheckout::notPurchasable($product->name);
            }

            $cart = Cart::query()->firstOrCreate(['user_id' => $buyer->id]);

            $line = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->first();

            $wanted = $line ? $line->quantity + $quantity : $quantity;

            if ($wanted > $available) {
                throw InvalidCart::because(
                    "[{$product->name}] only has {$available} available right now."
                );
            }

            CartItem::query()->updateOrCreate(
                ['cart_id' => $cart->id, 'product_id' => $product->id],
                ['quantity' => $wanted],
            );

            return $cart->refresh();
        });
    }
}

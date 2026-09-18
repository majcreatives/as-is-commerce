<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\InvalidCart;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Orders\Actions\FoldShopPurchaseToCart;
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
 *
 * A PENDING SHOP ORDER FOLDS FIRST. An order still awaiting payment is a
 * frozen snapshot of an earlier basket. Adding to the cart while it exists
 * would leave those frozen lines unplaced, so the fold calls it back into the
 * cart before the availability below is read -- releasing the stock it held
 * so that stock counts again. If a payment attempt is in flight the fold
 * refuses, because the basket is being paid for; the explicit move-back
 * action is the only kind of fold allowed to abandon it.
 */
final class AddToCart
{
    public function __construct(
        private readonly ProductDiscoveryQuery $products,
        private readonly FoldShopPurchaseToCart $folds,
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

            // One basket row, locked: concurrent adds for the same customer must
            // read-and-write the line atomically or a slower request could
            // overwrite a faster one's quantity.
            $cart = Cart::query()->firstOrCreate(['user_id' => $buyer->id]);
            $cart = Cart::query()->whereKey($cart->getKey())->lockForUpdate()->firstOrFail();

            // An unpaid snapshot of an earlier basket folds back into the cart
            // first: the stock it was holding counts again below, and nothing
            // stays silently unpaid while the basket grows.
            $this->folds->handle($buyer);

            // Re-read under the fold: any reservation the old order released
            // is part of what is available now.
            $product = $product->fresh();

            $available = $product->availableStock();

            if ($available < 1) {
                throw InvalidCheckout::notPurchasable($product->name);
            }

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

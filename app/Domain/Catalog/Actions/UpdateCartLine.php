<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\InvalidCart;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Change how many of a product a cart line wants, or remove the line.
 *
 * Still just intent: editing a line touches no ledger and no reservation. The
 * same server-side rules that admit a line to the cart control how it grows.
 * The browser sends only a product identity and a "how many" -- never a
 * price, a discount or a total.
 *
 * REMOVING IS ALWAYS ALLOWED, even for a product that has since entered an
 * auction or run short of stock: a customer shrinking their basket is never a
 * commercial claim on anything. Growth re-runs the add rules -- purchasable,
 * not auction-held, capped at available stock.
 */
final class UpdateCartLine
{
    public function __construct(
        private readonly ProductDiscoveryQuery $products,
    ) {}

    /**
     * @param  int  $quantity  The new quantity for the line. Zero or less
     *                         removes the line.
     */
    public function handle(User $buyer, CartItem $line, int $quantity): Cart
    {
        return DB::transaction(function () use ($buyer, $line, $quantity): Cart {
            $cart = Cart::query()->forUser($buyer)->first();

            $owned = $cart === null
                ? null
                : CartItem::query()
                    ->whereKey($line->getKey())
                    ->where('cart_id', $cart->id)
                    ->first();

            if ($owned === null) {
                throw InvalidCart::because('That cart line does not belong to you.');
            }

            if ($quantity < 1) {
                $owned->delete();

                return $owned->cart->refresh();
            }

            $product = $owned->product()->firstOrFail();

            // Growing the line claims more of the ledger's available stock, so
            // it must satisfy the same rules as adding the line.
            if ($quantity > $owned->quantity) {
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

                if ($quantity > $available) {
                    throw InvalidCart::because(
                        "[{$product->name}] only has {$available} available right now."
                    );
                }
            }

            $owned->quantity = $quantity;
            $owned->save();

            return $owned->cart->refresh();
        });
    }
}

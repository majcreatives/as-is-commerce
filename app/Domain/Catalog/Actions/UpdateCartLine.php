<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Exceptions\InvalidCart;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Orders\Actions\FoldShopPurchaseToCart;
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
 *
 * A PENDING SHOP ORDER FOLDS FIRST, exactly as when a line is added: an order
 * still awaiting payment is a frozen snapshot of the basket, and editing the
 * cart while it exists would leave those frozen lines unplaced. The fold runs
 * before the availability below is read so stock it held counts again. If a
 * payment attempt is in flight the fold refuses (the basket is being paid
 * for); moving it back to the cart is the only action allowed to abandon it.
 */
final class UpdateCartLine
{
    public function __construct(
        private readonly ProductDiscoveryQuery $products,
        private readonly FoldShopPurchaseToCart $folds,
    ) {}

    /**
     * @param  int  $quantity  The new quantity for the line. Zero or less
     *                         removes the line.
     */
    public function handle(User $buyer, CartItem $line, int $quantity): Cart
    {
        return DB::transaction(function () use ($buyer, $line, $quantity): Cart {
            $cart = Cart::query()->forUser($buyer)->lockForUpdate()->first();

            $owned = $cart === null
                ? null
                : CartItem::query()
                    ->whereKey($line->getKey())
                    ->where('cart_id', $cart->id)
                    ->first();

            if ($owned === null) {
                throw InvalidCart::because('That cart line does not belong to you.');
            }

            // An unpaid snapshot of an earlier basket folds back into the cart
            // first: the stock it was holding counts again below, and nothing
            // stays silently unpaid while the basket is edited. The fold may
            // restore lines, so the row is re-read after it.
            $this->folds->handle($buyer);

            $owned = CartItem::query()
                ->whereKey($owned->getKey())
                ->where('cart_id', $cart->id)
                ->firstOrFail();

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

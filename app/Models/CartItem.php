<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a shopping cart: a product and how many of it are wanted.
 *
 * A line is intent, not a reservation and not a price. The product's current
 * price is shown to the customer and the placement action re-reads both price
 * and stock from the product row before the order is frozen.
 *
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property int $quantity
 */
class CartItem extends Model
{
    /**
     * Written by the cart service, never by request input.
     *
     * @var list<string>
     */
    protected $fillable = ['cart_id', 'product_id', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * The product this line wants.
     *
     * Price and stock are read from the product row at placement, never from
     * anything stored on the line.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

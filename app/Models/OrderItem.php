<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line on an order: what was bought, and what it cost.
 *
 * EVERYTHING IS SNAPSHOTTED. The product's name, SKU and price are copied here
 * at checkout and never read back off the product afterwards. Products get
 * renamed, re-SKU'd and repriced; an order from last year must still say what
 * was actually bought and what was actually charged for it.
 *
 * Append-only, enforced by a trigger. A line on an order is a statement about
 * a transaction, and one that could be edited afterwards would be evidence of
 * nothing.
 *
 * Both acquisition paths buy exactly one thing, so every order in this stage
 * has one line. The structure is a proper order-item table anyway, because
 * retrofitting one onto orders that assumed a single product is a far worse
 * job than having it from the start.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property string $product_name_snapshot
 * @property string $sku_snapshot
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $discount_minor
 * @property int $line_total_minor
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
class OrderItem extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'line_total_minor' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The unit price as charged, from the snapshot rather than the product.
     */
    public function unitPrice(): Money
    {
        return Money::fromMinor($this->unit_price_minor, $this->order->currency);
    }

    public function discount(): Money
    {
        return Money::fromMinor($this->discount_minor, $this->order->currency);
    }

    public function lineTotal(): Money
    {
        return Money::fromMinor($this->line_total_minor, $this->order->currency);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The product as it is now.
     *
     * For linking to a catalog page, not for reading prices or names: those
     * come from this row's own snapshots.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

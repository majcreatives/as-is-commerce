<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryTransactionType;
use App\Models\Concerns\IsAppendOnly;
use Database\Factories\InventoryTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One immutable movement of stock.
 *
 * Every change to a product's stock has a row here saying what moved, by how
 * much, what the resulting state was, why, and at whose hand. Rows are never
 * updated or deleted -- database triggers refuse both -- so a stock history
 * cannot be quietly rewritten to match a discrepancy someone would rather not
 * explain.
 *
 * A mistake is corrected by posting an adjustment in the opposite direction,
 * leaving both the error and the correction on the record.
 *
 * @property int $id
 * @property int $product_id
 * @property InventoryTransactionType $type
 * @property int $quantity_delta Signed: positive adds, negative removes.
 * @property int $stock_on_hand_after
 * @property int $stock_reserved_after
 * @property string|null $reason
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory, IsAppendOnly;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InventoryTransactionType::class,
            'quantity_delta' => 'integer',
            'stock_on_hand_after' => 'integer',
            'stock_reserved_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What caused the movement -- an order, a return. Polymorphic because
     * those tables belong to later stages.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Stock available after this movement.
     */
    public function availableAfter(): int
    {
        return max(0, $this->stock_on_hand_after - $this->stock_reserved_after);
    }

    /**
     * The delta as a statement would show it.
     */
    public function signedDelta(): string
    {
        return ($this->quantity_delta > 0 ? '+' : '').number_format($this->quantity_delta);
    }
}

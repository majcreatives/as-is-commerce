<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\InvalidStockMovement;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductStatus;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only sanctioned way to move stock.
 *
 * Every method writes the inventory row and the product's stock projection
 * inside a single transaction. There is no path that changes one without the
 * other, so a product cannot end up claiming stock the ledger does not
 * account for.
 *
 * LOCKING. The product row is taken with SELECT ... FOR UPDATE before its
 * stock is read. That is what makes overselling impossible rather than
 * merely unlikely: a second reservation blocks until the first commits and
 * then reads the stock the first left behind. Checking availability and then
 * writing without the lock would let two requests both see the last item.
 *
 * Stock can never go negative, and reserved can never exceed on hand --
 * enforced here, and again by database CHECK constraints for any path that
 * bypasses this class.
 *
 * Reservation, Release and Sale exist and are tested, but nothing calls them
 * yet: they belong to the Buy Now checkout, which is a later stage.
 */
class InventoryService
{
    /**
     * Record the first stock for a product.
     *
     * @throws InvalidStockMovement
     */
    public function initialStock(
        Product $product,
        int $quantity,
        ?string $reason = null,
        ?User $actor = null,
    ): InventoryTransaction {
        if ($product->inventoryTransactions()->exists()) {
            throw InvalidStockMovement::because(
                'This product already has stock history. Use a restock or an adjustment instead.'
            );
        }

        return $this->apply(
            product: $product,
            type: InventoryTransactionType::InitialStock,
            delta: $quantity,
            reason: $reason ?? 'Opening stock.',
            actor: $actor,
        );
    }

    public function restock(
        Product $product,
        int $quantity,
        ?string $reason = null,
        ?User $actor = null,
    ): InventoryTransaction {
        return $this->apply(
            product: $product,
            type: InventoryTransactionType::Restock,
            delta: $quantity,
            reason: $reason ?? 'Stock received.',
            actor: $actor,
        );
    }

    /**
     * A correction by an administrator, in either direction.
     *
     * The only type that may move stock both ways, and the only one an
     * administrator may post by hand. A reason is required: an unexplained
     * stock change is indistinguishable from shrinkage when someone reviews
     * it later.
     */
    public function adjust(
        Product $product,
        int $delta,
        string $reason,
        ?User $actor = null,
    ): InventoryTransaction {
        if (trim($reason) === '') {
            throw InvalidStockMovement::because('An inventory adjustment requires a reason.');
        }

        return $this->apply(
            product: $product,
            type: InventoryTransactionType::ManualAdjustment,
            delta: $delta,
            reason: trim($reason),
            actor: $actor,
        );
    }

    /**
     * Set stock aside for an in-progress purchase.
     *
     * Not wired to anything yet. The future Buy Now checkout will call this
     * before taking payment, so two customers cannot buy the same last item.
     */
    public function reserve(
        Product $product,
        int $quantity,
        ?Model $reference = null,
        ?string $reason = null,
        ?User $actor = null,
    ): InventoryTransaction {
        return $this->apply(
            product: $product,
            type: InventoryTransactionType::Reservation,
            delta: -abs($quantity),
            reason: $reason ?? 'Reserved for a purchase in progress.',
            actor: $actor,
            reference: $reference,
        );
    }

    /**
     * Give a reservation back because the purchase did not complete.
     */
    public function release(
        Product $product,
        int $quantity,
        ?Model $reference = null,
        ?string $reason = null,
        ?User $actor = null,
    ): InventoryTransaction {
        return $this->apply(
            product: $product,
            type: InventoryTransactionType::Release,
            delta: abs($quantity),
            reason: $reason ?? 'Reservation released.',
            actor: $actor,
            reference: $reference,
        );
    }

    /**
     * Stock that left because it was sold.
     *
     * Consumes the matching reservation as it goes, so a sale following a
     * reservation nets correctly rather than removing the stock twice.
     */
    public function recordSale(
        Product $product,
        int $quantity,
        ?Model $reference = null,
        ?string $reason = null,
        ?User $actor = null,
    ): InventoryTransaction {
        return $this->apply(
            product: $product,
            type: InventoryTransactionType::Sale,
            delta: -abs($quantity),
            reason: $reason ?? 'Sold.',
            actor: $actor,
            reference: $reference,
        );
    }

    public function recordReturn(
        Product $product,
        int $quantity,
        ?Model $reference = null,
        ?string $reason = null,
        ?User $actor = null,
    ): InventoryTransaction {
        return $this->apply(
            product: $product,
            type: InventoryTransactionType::Return,
            delta: abs($quantity),
            reason: $reason ?? 'Returned by a customer.',
            actor: $actor,
            reference: $reference,
        );
    }

    // ------------------------------------------------------------ Internals

    /**
     * Apply one movement: validate, write the trail, update the projection.
     *
     * @throws InvalidStockMovement
     */
    private function apply(
        Product $product,
        InventoryTransactionType $type,
        int $delta,
        ?string $reason,
        ?User $actor,
        ?Model $reference = null,
    ): InventoryTransaction {
        if ($delta === 0) {
            throw InvalidStockMovement::because('A stock movement must change the quantity.');
        }

        $requiredSign = $type->requiredSign();

        if ($requiredSign !== null && ($delta <=> 0) !== $requiredSign) {
            throw InvalidStockMovement::because(
                "A {$type->label()} movement cannot have a quantity of {$delta}."
            );
        }

        return DB::transaction(function () use ($product, $type, $delta, $reason, $actor, $reference): InventoryTransaction {
            // Re-read under lock. The instance passed in may be stale, and
            // acting on a stale count is exactly how overselling happens.
            $locked = Product::whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            [$onHand, $reserved] = $this->resolveNewState($locked, $type, $delta);

            $transaction = InventoryTransaction::create([
                'product_id' => $locked->id,
                'type' => $type,
                'quantity_delta' => $delta,
                'stock_on_hand_after' => $onHand,
                'stock_reserved_after' => $reserved,
                'reason' => $reason,
                'reference_type' => $reference === null ? null : $reference::class,
                'reference_id' => $reference?->getKey(),
                'created_by' => $actor?->id,
            ]);

            $this->writeProjection($locked, $onHand, $reserved);

            Log::info('Stock movement recorded', [
                'operation' => 'inventory.'.$type->value,
                'product_id' => $locked->id,
                'sku' => $locked->sku,
                'transaction_id' => $transaction->id,
                'delta' => $delta,
                'stock_on_hand_after' => $onHand,
                'stock_reserved_after' => $reserved,
                'actor_id' => $actor?->id,
            ]);

            return $transaction;
        });
    }

    /**
     * Work out the resulting stock state, refusing anything impossible.
     *
     * @return array{int, int} on hand, reserved
     */
    private function resolveNewState(Product $locked, InventoryTransactionType $type, int $delta): array
    {
        $onHand = $locked->stock_on_hand;
        $reserved = $locked->stock_reserved;

        if ($type->affectsReservedStock()) {
            // A reservation does not remove stock from the building, it marks
            // it as spoken for. Only the reserved figure moves.
            $reserved -= $delta;

            if ($delta < 0) {
                $available = $onHand - $locked->stock_reserved;

                if (abs($delta) > $available) {
                    throw InvalidStockMovement::insufficientAvailable(abs($delta), $available);
                }
            } elseif ($reserved < 0) {
                throw InvalidStockMovement::wouldReleaseMoreThanReserved($delta, $locked->stock_reserved);
            }

            return [$onHand, $reserved];
        }

        $onHand += $delta;

        if ($onHand < 0) {
            throw InvalidStockMovement::wouldGoNegative($locked->stock_on_hand, $delta);
        }

        // A sale consumes the reservation that preceded it, so the two do not
        // remove the same item twice.
        if ($type === InventoryTransactionType::Sale) {
            $reserved = max(0, $reserved + $delta);
        }

        // Removing stock must never strand more reserved than remains.
        if ($reserved > $onHand) {
            throw InvalidStockMovement::because(
                "Refusing the movement: it would leave {$reserved} reserved against {$onHand} on hand."
            );
        }

        return [$onHand, $reserved];
    }

    /**
     * Write the materialized stock, and keep the product's status honest.
     *
     * Guarded so this cannot happen anywhere but here, and always alongside
     * the movement that accounts for it.
     */
    private function writeProjection(Product $product, int $onHand, int $reserved): void
    {
        Product::permittingStockWrites(function () use ($product, $onHand, $reserved): void {
            $product->stock_on_hand = $onHand;
            $product->stock_reserved = $reserved;

            // Status follows availability, but only between the two states
            // that describe it. A draft, inactive or archived product is not
            // made Active by stock arriving -- that is a decision for someone
            // to make, not a side effect of a delivery.
            $available = $onHand - $reserved;

            if ($available <= 0 && $product->status === ProductStatus::Active) {
                $product->status = ProductStatus::OutOfStock;
            } elseif ($available > 0 && $product->status === ProductStatus::OutOfStock) {
                $product->status = ProductStatus::Active;
            }

            $product->save();
        });
    }
}

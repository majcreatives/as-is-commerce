<?php

declare(strict_types=1);

use App\Domain\Catalog\Exceptions\InvalidStockMovement;
use App\Domain\Catalog\Exceptions\StockMutationForbidden;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Shared\Ledger\FinancialHistoryIsImmutable;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductStatus;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedRoles();

    $this->inventory = app(InventoryService::class);
    $this->product = Product::factory()->active()->create();
});

// -------------------------------------------------------------- Movements

it('records opening stock', function (): void {
    $movement = $this->inventory->initialStock($this->product, 10);

    expect($movement->type)->toBe(InventoryTransactionType::InitialStock)
        ->and($movement->quantity_delta)->toBe(10)
        ->and($movement->stock_on_hand_after)->toBe(10)
        ->and($this->product->fresh()->stock_on_hand)->toBe(10);
});

it('refuses a second opening stock', function (): void {
    $this->inventory->initialStock($this->product, 10);

    expect(fn (): InventoryTransaction => $this->inventory->initialStock($this->product->fresh(), 5))
        ->toThrow(InvalidStockMovement::class);
});

it('adds stock on a restock', function (): void {
    $this->inventory->initialStock($this->product, 10);
    $movement = $this->inventory->restock($this->product->fresh(), 5);

    expect($movement->quantity_delta)->toBe(5)
        ->and($movement->stock_on_hand_after)->toBe(15)
        ->and($this->product->fresh()->stock_on_hand)->toBe(15);
});

it('adjusts stock in either direction', function (int $delta, int $expected): void {
    $this->inventory->initialStock($this->product, 10);

    $movement = $this->inventory->adjust($this->product->fresh(), $delta, 'Stock count correction.');

    expect($movement->quantity_delta)->toBe($delta)
        ->and($movement->stock_on_hand_after)->toBe($expected)
        ->and($this->product->fresh()->stock_on_hand)->toBe($expected);
})->with([
    'upward' => [3, 13],
    'downward' => [-4, 6],
]);

it('requires a reason for an adjustment', function (string $reason): void {
    $this->inventory->initialStock($this->product, 10);

    expect(fn (): InventoryTransaction => $this->inventory->adjust($this->product->fresh(), -1, $reason))
        ->toThrow(InvalidStockMovement::class);
})->with(['empty' => '', 'whitespace' => '   ']);

it('refuses a zero-quantity movement', function (): void {
    expect(fn (): InventoryTransaction => $this->inventory->restock($this->product, 0))
        ->toThrow(InvalidStockMovement::class);
});

// ----------------------------------------------------------- Never negative

it('refuses a movement that would drive stock negative', function (): void {
    $this->inventory->initialStock($this->product, 5);

    expect(fn (): InventoryTransaction => $this->inventory->adjust($this->product->fresh(), -6, 'Too many.'))
        ->toThrow(InvalidStockMovement::class);

    expect($this->product->fresh()->stock_on_hand)->toBe(5);
});

it('leaves nothing behind when a movement is refused', function (): void {
    $this->inventory->initialStock($this->product, 5);
    $before = InventoryTransaction::count();

    try {
        $this->inventory->adjust($this->product->fresh(), -50, 'Impossible.');
    } catch (InvalidStockMovement) {
        // expected
    }

    expect(InventoryTransaction::count())->toBe($before)
        ->and($this->product->fresh()->stock_on_hand)->toBe(5);
});

// ------------------------------------------------------------- Reservations

it('reserves stock without removing it from the shelf', function (): void {
    $this->inventory->initialStock($this->product, 10);

    $this->inventory->reserve($this->product->fresh(), 3);

    $product = $this->product->fresh();

    expect($product->stock_on_hand)->toBe(10)
        ->and($product->stock_reserved)->toBe(3)
        ->and($product->availableStock())->toBe(7);
});

it('refuses to reserve more than is available', function (): void {
    $this->inventory->initialStock($this->product, 5);
    $this->inventory->reserve($this->product->fresh(), 4);

    expect(fn (): InventoryTransaction => $this->inventory->reserve($this->product->fresh(), 2))
        ->toThrow(InvalidStockMovement::class);

    expect($this->product->fresh()->stock_reserved)->toBe(4);
});

it('releases a reservation', function (): void {
    $this->inventory->initialStock($this->product, 10);
    $this->inventory->reserve($this->product->fresh(), 4);
    $this->inventory->release($this->product->fresh(), 3);

    $product = $this->product->fresh();

    expect($product->stock_reserved)->toBe(1)
        ->and($product->availableStock())->toBe(9);
});

it('refuses to release more than is reserved', function (): void {
    $this->inventory->initialStock($this->product, 10);
    $this->inventory->reserve($this->product->fresh(), 2);

    expect(fn (): InventoryTransaction => $this->inventory->release($this->product->fresh(), 5))
        ->toThrow(InvalidStockMovement::class);
});

/*
 * A sale consumes the reservation that preceded it. Without that, reserving
 * then selling would remove the same item from stock twice.
 */
it('consumes the reservation when the sale completes', function (): void {
    $this->inventory->initialStock($this->product, 10);
    $this->inventory->reserve($this->product->fresh(), 2);
    $this->inventory->recordSale($this->product->fresh(), 2);

    $product = $this->product->fresh();

    expect($product->stock_on_hand)->toBe(8)
        ->and($product->stock_reserved)->toBe(0)
        ->and($product->availableStock())->toBe(8);
});

it('adds stock back on a return', function (): void {
    $this->inventory->initialStock($this->product, 10);
    $this->inventory->recordSale($this->product->fresh(), 3);
    $this->inventory->recordReturn($this->product->fresh(), 1);

    expect($this->product->fresh()->stock_on_hand)->toBe(8);
});

// ----------------------------------------------------------- Auditability

it('records who moved the stock and why', function (): void {
    $admin = userWithRole('admin');

    $movement = $this->inventory->initialStock($this->product, 10, 'Delivery from supplier.', $admin);

    expect($movement->reason)->toBe('Delivery from supplier.')
        ->and($movement->created_by)->toBe($admin->id)
        ->and($movement->created_at)->not->toBeNull();
});

it('keeps a complete trail across many movements', function (): void {
    $this->inventory->initialStock($this->product, 10);
    $this->inventory->restock($this->product->fresh(), 5);
    $this->inventory->adjust($this->product->fresh(), -2, 'Damaged in transit.');

    $movements = InventoryTransaction::where('product_id', $this->product->id)->orderBy('id')->get();

    expect($movements)->toHaveCount(3)
        // The deltas sum to the final stock, so the trail explains the number.
        ->and((int) $movements->sum('quantity_delta'))->toBe(13)
        ->and($this->product->fresh()->stock_on_hand)->toBe(13)
        ->and($movements->last()->stock_on_hand_after)->toBe(13);
});

it('records the resulting balance on every movement', function (): void {
    $this->inventory->initialStock($this->product, 10);
    $this->inventory->restock($this->product->fresh(), 5);

    $running = 0;

    foreach (InventoryTransaction::orderBy('id')->get() as $movement) {
        $running += $movement->quantity_delta;
        expect($movement->stock_on_hand_after)->toBe($running);
    }
});

// ------------------------------------------------------------ Immutability

it('refuses to edit a stock movement', function (): void {
    $movement = $this->inventory->initialStock($this->product, 10);
    $movement->quantity_delta = 999;

    expect(fn () => $movement->save())->toThrow(FinancialHistoryIsImmutable::class);
});

it('blocks a raw update of a stock movement', function (): void {
    $movement = $this->inventory->initialStock($this->product, 10);

    expect(fn () => DB::statement(
        'UPDATE inventory_transactions SET quantity_delta = 999 WHERE id = ?', [$movement->id]
    ))->toThrow(QueryException::class);
});

it('blocks a raw delete of a stock movement', function (): void {
    $movement = $this->inventory->initialStock($this->product, 10);

    expect(fn () => DB::statement('DELETE FROM inventory_transactions WHERE id = ?', [$movement->id]))
        ->toThrow(QueryException::class);
});

it('rejects a zero-delta movement at the database level', function (): void {
    expect(fn () => InventoryTransaction::factory()->create(['quantity_delta' => 0]))
        ->toThrow(QueryException::class);
});

// ------------------------------------------------------- Projection guard

/*
 * Stock is derived from the ledger. Letting application code write it would
 * create a second source of truth with no audit trail behind it.
 */
it('refuses a direct write to stock on hand', function (): void {
    $product = $this->product;
    $product->stock_on_hand = 999;

    expect(fn () => $product->save())->toThrow(StockMutationForbidden::class);

    expect($this->product->fresh()->stock_on_hand)->toBe(0);
});

it('refuses a direct write to reserved stock', function (): void {
    $product = $this->product;
    $product->stock_reserved = 5;

    expect(fn () => $product->save())->toThrow(StockMutationForbidden::class);
});

it('refuses to mass-assign stock', function (): void {
    $product = $this->product;

    expect(fn () => $product->fill(['stock_on_hand' => 500]))
        ->toThrow(MassAssignmentException::class);
});

it('rejects reserved exceeding on hand at the database level', function (): void {
    $product = Product::factory()->withStock(5)->create();

    expect(fn () => DB::statement(
        'UPDATE products SET stock_reserved = 99 WHERE id = ?', [$product->id]
    ))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- Status

it('marks an active product out of stock when the last item goes', function (): void {
    $this->inventory->initialStock($this->product, 2);
    expect($this->product->fresh()->status)->toBe(ProductStatus::Active);

    $this->inventory->recordSale($this->product->fresh(), 2);

    expect($this->product->fresh()->status)->toBe(ProductStatus::OutOfStock);
});

it('brings an out-of-stock product back when stock arrives', function (): void {
    $this->inventory->initialStock($this->product, 1);
    $this->inventory->recordSale($this->product->fresh(), 1);
    expect($this->product->fresh()->status)->toBe(ProductStatus::OutOfStock);

    $this->inventory->restock($this->product->fresh(), 3);

    expect($this->product->fresh()->status)->toBe(ProductStatus::Active);
});

/*
 * Stock arriving is not a decision to publish. A draft or archived product
 * stays where it is.
 */
it('does not publish a draft product just because stock arrived', function (ProductStatus $status): void {
    $product = Product::factory()->status($status)->create();

    $this->inventory->initialStock($product, 10);

    expect($product->fresh()->status)->toBe($status);
})->with([
    'draft' => ProductStatus::Draft,
    'inactive' => ProductStatus::Inactive,
    'archived' => ProductStatus::Archived,
]);

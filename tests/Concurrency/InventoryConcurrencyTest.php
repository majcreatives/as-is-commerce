<?php

declare(strict_types=1);

use App\Domain\Catalog\Exceptions\InvalidStockMovement;
use App\Domain\Catalog\Services\InventoryService;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Overselling, tested against real MySQL locks.
 *
 * Truncation rather than a wrapping transaction, for the reason given in the
 * other concurrency suites: a second connection cannot see rows an
 * uncommitted transaction has written, so under RefreshDatabase these tests
 * would observe an empty database and pass without exercising anything.
 */

beforeEach(function (): void {
    seedRoles();

    $this->inventory = app(InventoryService::class);
    $this->product = Product::factory()->active()->create();

    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');
});

afterEach(function (): void {
    try {
        while (DB::connection('second')->transactionLevel() > 0) {
            DB::connection('second')->rollBack();
        }
    } catch (Throwable) {
        // Connection already gone.
    }

    DB::purge('second');
});

/*
 * The primitive everything else rests on. Without a real lock on the product
 * row, two requests both read the same stock and both decide there is enough.
 */
it('serializes access to a product row across connections', function (): void {
    $this->inventory->initialStock($this->product, 1);

    DB::beginTransaction();
    DB::table('products')->where('id', $this->product->id)->lockForUpdate()->first();

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')
            ->table('products')
            ->where('id', $this->product->id)
            ->lockForUpdate()
            ->first();
    } catch (QueryException $e) {
        $blocked = str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second connection must not take the product lock while it is held.');
});

/*
 * The scenario from the specification: one item, two competing reservations.
 * Exactly one may succeed.
 */
it('does not let two reservations take the same last item', function (): void {
    $this->inventory->initialStock($this->product, 1);

    $succeeded = 0;
    $refused = 0;

    foreach (range(1, 2) as $ignored) {
        try {
            // Each attempt gets a separately loaded product, as two
            // independent requests would.
            $this->inventory->reserve(Product::findOrFail($this->product->id), 1);
            $succeeded++;
        } catch (InvalidStockMovement) {
            $refused++;
        }
    }

    $product = $this->product->fresh();

    expect($succeeded)->toBe(1)
        ->and($refused)->toBe(1)
        ->and($product->stock_reserved)->toBe(1)
        ->and($product->stock_on_hand)->toBe(1)
        ->and($product->availableStock())->toBe(0);
});

it('never lets stock or availability go negative under repeated attempts', function (): void {
    $this->inventory->initialStock($this->product, 3);

    foreach (range(1, 10) as $ignored) {
        try {
            $this->inventory->reserve(Product::findOrFail($this->product->id), 1);
        } catch (InvalidStockMovement) {
            break;
        }
    }

    $product = $this->product->fresh();

    expect($product->stock_reserved)->toBe(3)
        ->and($product->availableStock())->toBe(0)
        ->and($product->stock_on_hand)->toBeGreaterThanOrEqual(0)
        ->and($product->stock_reserved)->toBeLessThanOrEqual($product->stock_on_hand);
});

/*
 * The failure a lock protects against: acting on a count that was true when
 * the request started but is not true any more.
 */
it('ignores a stale stock count on the model it was handed', function (): void {
    $this->inventory->initialStock($this->product, 5);

    $stale = Product::findOrFail($this->product->id);
    expect($stale->stock_on_hand)->toBe(5);

    // The stock is sold elsewhere meanwhile.
    $this->inventory->recordSale(Product::findOrFail($this->product->id), 5);

    // The stale object still claims 5, but the service re-reads under lock.
    expect($stale->stock_on_hand)->toBe(5);

    expect(fn (): InventoryTransaction => $this->inventory->reserve($stale, 3))
        ->toThrow(InvalidStockMovement::class);

    expect($this->product->fresh()->stock_on_hand)->toBe(0);
});

it('keeps the movement trail consistent with the projection', function (): void {
    $this->inventory->initialStock($this->product, 20);

    foreach (range(1, 15) as $ignored) {
        $this->inventory->recordSale(Product::findOrFail($this->product->id), 1);
    }

    $product = $this->product->fresh();
    $sum = (int) InventoryTransaction::where('product_id', $product->id)->sum('quantity_delta');

    // The deltas add up to the stored figure, which they only can if every
    // movement and every projection write happened in the same transaction.
    expect($sum)->toBe(5)
        ->and($product->stock_on_hand)->toBe(5);
});

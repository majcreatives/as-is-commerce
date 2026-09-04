<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Catalog;

use App\Domain\Catalog\Services\InventoryService;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryTransaction;
use App\Models\Product;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stock levels and the movement history behind them.
 *
 * There is deliberately no "set stock to N" control. Stock changes only by
 * posting a movement with a reason, which leaves a row someone can read back
 * later. A field that overwrote the number would destroy exactly the trail
 * this stage exists to create.
 *
 * Only the movement types an administrator may legitimately post by hand are
 * offered. Sales, reservations and releases are consequences of a customer
 * action and belong to the flow that causes them.
 */
#[Layout('components.layouts.app')]
#[Title('Inventory')]
class InventoryManager extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public ?int $adjustingId = null;

    public string $movementType = 'restock';

    public int $quantity = 0;

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('inventory.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startAdjustment(int $productId): void
    {
        $this->authorize('inventory.adjust');

        $this->reset('quantity', 'reason');
        $this->movementType = 'restock';
        $this->adjustingId = $productId;
    }

    public function cancelAdjustment(): void
    {
        $this->reset('adjustingId', 'quantity', 'reason');
    }

    public function postMovement(InventoryService $inventory): void
    {
        $this->authorize('inventory.adjust');

        $validated = $this->validate([
            'movementType' => ['required', Rule::in($this->postableTypeValues())],
            // Signed: a manual adjustment may legitimately be negative, which
            // is how shrinkage and miscounts get recorded honestly.
            'quantity' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'quantity.not_in' => 'A movement must change the quantity.',
            'reason.required' => 'Every stock movement needs a reason.',
            'reason.min' => 'Give a reason someone reviewing this later will understand.',
        ]);

        $product = Product::findOrFail($this->adjustingId);
        $type = InventoryTransactionType::from($validated['movementType']);

        try {
            match ($type) {
                InventoryTransactionType::InitialStock => $inventory->initialStock(
                    $product, abs($validated['quantity']), $validated['reason'], auth()->user(),
                ),
                InventoryTransactionType::Restock => $inventory->restock(
                    $product, abs($validated['quantity']), $validated['reason'], auth()->user(),
                ),
                InventoryTransactionType::Return => $inventory->recordReturn(
                    $product, abs($validated['quantity']), null, $validated['reason'], auth()->user(),
                ),
                default => $inventory->adjust(
                    $product, $validated['quantity'], $validated['reason'], auth()->user(),
                ),
            };
        } catch (DomainException $e) {
            $this->addError('quantity', $e->getMessage());

            return;
        }

        $this->reset('adjustingId', 'quantity', 'reason');

        session()->flash('status', 'Stock movement recorded.');
    }

    /**
     * @return list<string>
     */
    private function postableTypeValues(): array
    {
        return array_map(
            fn (InventoryTransactionType $t): string => $t->value,
            array_filter(
                InventoryTransactionType::cases(),
                fn (InventoryTransactionType $t): bool => $t->isManuallyPostable(),
            ),
        );
    }

    /**
     * @return list<InventoryTransactionType>
     */
    public function postableTypes(): array
    {
        return array_values(array_filter(
            InventoryTransactionType::cases(),
            fn (InventoryTransactionType $t): bool => $t->isManuallyPostable(),
        ));
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function products(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Product::query()
            ->when($term !== '', fn ($q) => $q->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * @return LengthAwarePaginator<int, InventoryTransaction>
     */
    public function movements(): LengthAwarePaginator
    {
        return InventoryTransaction::query()
            ->with(['product', 'creator'])
            ->orderByDesc('id')
            ->paginate(20, pageName: 'movements');
    }

    public function render(): View
    {
        return view('livewire.admin.catalog.inventory-manager', [
            'products' => $this->products(),
            'movements' => $this->movements(),
            'postableTypes' => $this->postableTypes(),
        ]);
    }
}

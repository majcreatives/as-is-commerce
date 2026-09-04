<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Orders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every order, for staff.
 *
 * READ-ONLY, and deliberately so. There is nothing here that changes an
 * amount, and nothing anywhere that marks an order paid: that state is only
 * reachable through a payment verified with the provider. Operational
 * progress -- processing, fulfilled -- lives on the detail screen where the
 * consequences are visible and each move is audited.
 *
 * The "needs attention" filter is the one that matters operationally: orders
 * whose payment succeeded but which could not be completed, because somebody
 * else acquired the item while the customer was paying. Real money with
 * nothing delivered, waiting for a person to decide what is owed.
 */
#[Layout('components.layouts.app')]
#[Title('Orders')]
class OrderManager extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    /** Only orders that were paid but could not be completed. */
    #[Url]
    public bool $blocked = false;

    public function mount(): void
    {
        $this->authorize('orders.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSource(): void
    {
        $this->resetPage();
    }

    public function updatedBlocked(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.orders.order-manager', [
            'orders' => $this->orders(),
            'statuses' => OrderStatus::cases(),
            'sources' => OrderSource::cases(),
            'blockedCount' => Order::query()->blocked()->count(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    private function orders(): LengthAwarePaginator
    {
        return Order::query()
            ->with(['user', 'items', 'auction', 'winningBid'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->source !== '', fn ($q) => $q->where('source', $this->source))
            ->when($this->blocked, fn ($q) => $q->blocked())
            ->when($this->search !== '', function ($q): void {
                $term = '%'.$this->search.'%';

                $q->where(function ($inner) use ($term): void {
                    $inner->where('order_number', 'like', $term)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhereHas('items', fn ($i) => $i->where('product_name_snapshot', 'like', $term)
                            ->orWhere('sku_snapshot', 'like', $term));
                });
            })
            ->orderByDesc('id')
            ->paginate(20);
    }
}

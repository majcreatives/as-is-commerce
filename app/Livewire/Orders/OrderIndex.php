<?php

declare(strict_types=1);

namespace App\Livewire\Orders;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A customer's own orders.
 *
 * Scoped to the signed-in user in the query itself rather than by a check
 * after the fact, so there is no path by which somebody else's order could be
 * listed here.
 */
#[Layout('components.layouts.app')]
#[Title('My orders')]
class OrderIndex extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('orders.view_own');
    }

    public function render(): View
    {
        return view('livewire.orders.order-index', [
            'orders' => $this->orders(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    private function orders(): LengthAwarePaginator
    {
        return Order::query()
            ->where('user_id', auth()->id())
            ->with(['items', 'auction'])
            ->orderByDesc('id')
            ->paginate(15);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Orders;

use App\Enums\DeliveryStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
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

    /**
     * Where the package is, for the operational question the order status
     * cannot answer. A delivery status value, or `none` for orders that have
     * no delivery at all -- which is not the same as one that has not started.
     */
    #[Url]
    public string $delivery = '';

    /** Placed on or after this date, in the display timezone. */
    #[Url]
    public string $from = '';

    /** Placed on or before this date. */
    #[Url]
    public string $to = '';

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

    public function updatedDelivery(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'source', 'blocked', 'delivery', 'from', 'to');
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.orders.order-manager', [
            'orders' => $this->orders(),
            'statuses' => OrderStatus::cases(),
            'sources' => OrderSource::cases(),
            'deliveryStatuses' => DeliveryStatus::cases(),
            'blockedCount' => Order::query()->blocked()->count(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    private function orders(): LengthAwarePaginator
    {
        $from = $this->boundary($this->from);
        $to = $this->boundary($this->to, endOfDay: true);

        return Order::query()
            ->with(['user', 'items', 'auction', 'winningBid'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->source !== '', fn ($q) => $q->where('source', $this->source))
            ->when($this->blocked, fn ($q) => $q->blocked())
            // `none` means no delivery record exists, which is a different
            // situation from one sitting at Pending and must not be folded
            // into it.
            ->when($this->delivery === 'none', fn ($q) => $q->whereDoesntHave('delivery'))
            ->when(
                $this->delivery !== '' && $this->delivery !== 'none',
                fn ($q) => $q->whereHas('delivery', fn ($d) => $d->where('status', $this->delivery)),
            )
            // Dates are read in the display timezone and compared in UTC,
            // because that is what is stored. An unparseable date filters
            // nothing rather than failing the page.
            ->when($from !== null, fn ($q) => $q->where('placed_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('placed_at', '<=', $to))
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

    /**
     * A date typed by an administrator, turned into an instant to compare
     * against stored UTC.
     *
     * A date means a day in the timezone the administrator is reading in, not
     * a day in UTC. Comparing the raw string would silently shift the boundary
     * by the display offset and quietly drop or add a day's orders.
     */
    private function boundary(string $date, bool $endOfDay = false): ?Carbon
    {
        if ($date === '') {
            return null;
        }

        $timezone = settings()->getString('display_timezone', 'UTC');

        try {
            $moment = Carbon::parse($date, $timezone);
        } catch (InvalidFormatException) {
            // A hand-edited URL parameter is not a reason to fail the page.
            return null;
        }

        return ($endOfDay ? $moment->endOfDay() : $moment->startOfDay())->utc();
    }
}

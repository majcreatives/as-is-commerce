<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The warehouse's own screen: what needs packing, what is out, what came back.
 *
 * READ AND ROUTE, NOT ACT. Every actual move happens on the order's own
 * detail screen, where the person doing it can see the address, the customer
 * and what was paid before they touch anything. A queue that let staff advance
 * packages from a list would make it easy to dispatch the wrong one, and the
 * whole point of this stage is that a manual process needs more care than an
 * automated one, not less.
 *
 * The counts across the top are the operational picture §43 asks for and
 * nothing more: how much work is at each stage. No money, no margins, no
 * customer contact details on a list anyone can leave open on a shared screen.
 */
#[Layout('components.layouts.app')]
#[Title('Fulfilment')]
class FulfilmentQueue extends Component
{
    use WithPagination;

    /** A DeliveryStatus value, `outstanding`, or `awaiting_address`. */
    #[Url]
    public string $filter = 'outstanding';

    #[Url]
    public string $region = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('deliveries.view');
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRegion(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.delivery.fulfilment-queue', [
            'deliveries' => $this->deliveries(),
            'counts' => $this->counts(),
            'regions' => $this->regions(),
            'statuses' => DeliveryStatus::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Delivery>
     */
    private function deliveries(): LengthAwarePaginator
    {
        return $this->scope()
            ->with(['order.user', 'order.items'])
            ->latest('id')
            ->paginate(20);
    }

    /**
     * @return Builder<Delivery>
     */
    private function scope(): Builder
    {
        $status = DeliveryStatus::tryFrom($this->filter);

        return Delivery::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->when($this->filter === 'outstanding', fn ($q) => $q->outstanding())
            // Packages the warehouse cannot start because the customer has not
            // said where they live. Listed separately so they are not counted
            // as work somebody is failing to do.
            ->when($this->filter === 'awaiting_address', fn ($q) => $q
                ->where('status', DeliveryStatus::Pending)
                ->whereNull('address_line'))
            ->when($this->region !== '', fn ($q) => $q->where('region', $this->region))
            ->when($this->search !== '', function ($q): void {
                $term = '%'.$this->search.'%';

                $q->where(fn ($inner) => $inner
                    ->where('reference', 'like', $term)
                    ->orWhere('tracking_reference', 'like', $term)
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', $term))
                    ->orWhereHas('order.user', fn ($u) => $u->where('name', 'like', $term)));
            });
    }

    /**
     * How much work sits at each stage.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $byStatus = Delivery::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (DeliveryStatus::cases() as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        $counts['awaiting_address'] = Delivery::query()
            ->where('status', DeliveryStatus::Pending)
            ->whereNull('address_line')
            ->count();

        $counts['outstanding'] = Delivery::query()->outstanding()->count();

        return $counts;
    }

    /**
     * The regions packages are actually going to.
     *
     * From the deliveries themselves rather than a fixed list of Ghana's
     * regions: a filter offering sixteen options where three have anything in
     * them is a filter nobody uses.
     *
     * @return list<string>
     */
    private function regions(): array
    {
        return Delivery::query()
            ->whereNotNull('region')
            ->distinct()
            ->orderBy('region')
            ->pluck('region')
            ->all();
    }
}

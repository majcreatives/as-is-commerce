<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Payments;

use App\Enums\CreditPurchaseStatus;
use App\Models\CreditPurchase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every credit purchase, for staff.
 *
 * Read-only. There is no control here that grants credits: administrative
 * credit changes go through the adjustment flow built in the ledger stage,
 * which requires a reason and leaves an audit trail.
 */
#[Layout('components.layouts.app')]
#[Title('Credit purchases')]
class PurchaseIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('credit_purchases.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, CreditPurchase>
     */
    public function purchases(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return CreditPurchase::query()
            ->with('user')
            ->when($term !== '', fn ($q) => $q->where(function ($inner) use ($term): void {
                $inner->where('provider_reference', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%"));
            }))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function render(): View
    {
        return view('livewire.admin.payments.purchase-index', [
            'purchases' => $this->purchases(),
            'statuses' => CreditPurchaseStatus::cases(),
        ]);
    }
}

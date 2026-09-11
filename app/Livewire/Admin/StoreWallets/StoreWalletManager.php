<?php

declare(strict_types=1);

namespace App\Livewire\Admin\StoreWallets;

use App\Domain\StoreWallet\Services\StoreWalletReconciler;
use App\Domain\StoreWallet\Services\StoreWalletReport;
use App\Models\StoreWallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Inspect every user's Store Wallet and verify the ledger that backs it.
 *
 * READ-ONLY. There is no control here that writes anything, and every balance
 * shown is derived from the immutable ledger. The ledger service owns every
 * mutation; this screen reports what it finds.
 *
 * BOUNDED. Verification runs against the wallets on the current page -- at
 * most the page size -- not every wallet on the platform. An operations screen
 * is most needed on the worst day, which is exactly the day an unbounded query
 * would take the site down with it.
 */
#[Layout('components.layouts.app')]
#[Title('Store Wallets')]
class StoreWalletManager extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('wallets.inspect');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, StoreWallet>
     */
    public function wallets(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return StoreWallet::query()
            ->with('user')
            ->when($term !== '', function ($query) use ($term): void {
                $query->whereHas('user', function ($q) use ($term): void {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function render(StoreWalletReconciler $reconciler): View
    {
        $wallets = $this->wallets();

        /** @var Collection<int, StoreWalletReport> $reports */
        $reports = collect($wallets->items())->mapWithKeys(
            fn (StoreWallet $wallet): array => [$wallet->id => $reconciler->reconcile($wallet)],
        );

        return view('livewire.admin.store-wallets.store-wallet-manager', [
            'wallets' => $wallets,
            'reports' => $reports,
        ]);
    }
}

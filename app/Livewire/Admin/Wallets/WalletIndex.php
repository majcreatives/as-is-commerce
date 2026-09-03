<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Wallets;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Find a user in order to inspect their wallets.
 */
#[Layout('components.layouts.app')]
#[Title('Wallets')]
class WalletIndex extends Component
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
     * @return LengthAwarePaginator<int, User>
     */
    public function users(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return User::query()
            // Eager loaded: without this the table would issue two queries
            // per row for the balances.
            ->with(['creditWallet', 'cashWallet'])
            ->when($term !== '', function ($query) use ($term): void {
                $query->where(function ($q) use ($term): void {
                    $q->where('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function render(): View
    {
        return view('livewire.admin.wallets.wallet-index', [
            'users' => $this->users(),
        ]);
    }
}

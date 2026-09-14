<?php

declare(strict_types=1);

namespace App\Livewire\Wallet;

use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\StoreWallet\Services\StoreWalletLedgerService;
use App\Models\CreditLot;
use App\Models\CreditTransaction;
use App\Models\StoreWalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A customer's view of their own wallets.
 *
 * Read-only. Buying credits requires payment processing, which belongs to a
 * later stage, so nothing here can change a balance.
 *
 * Balances shown are real: a new account genuinely holds zero credits and no
 * Store Wallet value, and that is what it displays.
 */
#[Layout('components.layouts.app')]
#[Title('Wallet')]
class WalletOverview extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'credits';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function render(
        CreditLedgerService $credits,
        StoreWalletLedgerService $store,
    ): View {
        $user = auth()->user();

        $creditWallet = $credits->walletFor($user);
        $storeWallet = $store->walletFor($user);

        return view('livewire.wallet.wallet-overview', [
            'creditWallet' => $creditWallet,
            'storeWallet' => $storeWallet,
            'spendableCredits' => $creditWallet->spendableBalance(),
            'expiringSoon' => $this->expiringSoon($creditWallet->id),
            'lots' => $this->activeLots($creditWallet->id),
            'creditTransactions' => $this->creditTransactions($creditWallet->id),
            'storeWalletTransactions' => $this->storeWalletTransactions($storeWallet->id),
        ]);
    }

    /**
     * Lots still holding spendable credits, in the order they will be spent,
     * so the customer can see which credits go first.
     *
     * @return Collection<int, CreditLot>
     */
    private function activeLots(int $walletId): Collection
    {
        return CreditLot::where('credit_wallet_id', $walletId)
            ->spendable()
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Credits that will lapse within the next month, if any.
     */
    private function expiringSoon(int $walletId): int
    {
        return (int) CreditLot::where('credit_wallet_id', $walletId)
            ->spendable()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addMonth())
            ->sum('remaining_amount');
    }

    /**
     * @return LengthAwarePaginator<int, CreditTransaction>
     */
    private function creditTransactions(int $walletId): LengthAwarePaginator
    {
        return CreditTransaction::where('credit_wallet_id', $walletId)
            ->orderByDesc('id')
            ->paginate(15, pageName: 'credits');
    }

    /**
     * @return LengthAwarePaginator<int, StoreWalletTransaction>
     */
    private function storeWalletTransactions(int $walletId): LengthAwarePaginator
    {
        return StoreWalletTransaction::where('store_wallet_id', $walletId)
            ->orderByDesc('id')
            ->paginate(15, pageName: 'store');
    }
}

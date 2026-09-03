<?php

declare(strict_types=1);

namespace App\Livewire\Wallet;

use App\Domain\Cash\Services\CashLedgerService;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Models\CashTransaction;
use App\Models\CreditLot;
use App\Models\CreditTransaction;
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
 * Balances shown are real: a new account genuinely holds zero credits and
 * GH0.00, and that is what it displays.
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
        CashLedgerService $cash,
    ): View {
        $user = auth()->user();

        $creditWallet = $credits->walletFor($user);
        $cashWallet = $cash->walletFor($user);

        return view('livewire.wallet.wallet-overview', [
            'creditWallet' => $creditWallet,
            'cashWallet' => $cashWallet,
            'spendableCredits' => $creditWallet->spendableBalance(),
            'expiringSoon' => $this->expiringSoon($creditWallet->id),
            'lots' => $this->activeLots($creditWallet->id),
            'creditTransactions' => $this->creditTransactions($creditWallet->id),
            'cashTransactions' => $this->cashTransactions($cashWallet->id),
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
     * @return LengthAwarePaginator<int, CashTransaction>
     */
    private function cashTransactions(int $walletId): LengthAwarePaginator
    {
        return CashTransaction::where('cash_wallet_id', $walletId)
            ->orderByDesc('id')
            ->paginate(15, pageName: 'cash');
    }
}

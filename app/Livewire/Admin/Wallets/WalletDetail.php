<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Wallets;

use App\Domain\Cash\Services\CashLedgerService;
use App\Domain\Credit\Actions\AdjustCredits;
use App\Domain\Credit\Services\CreditLedgerReconciler;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Credit\ValueObjects\ReconciliationReport;
use App\Enums\CreditLotSource;
use App\Models\CashTransaction;
use App\Models\CreditLot;
use App\Models\CreditTransaction;
use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Inspect one user's wallets, and adjust their credits.
 *
 * There is deliberately no control that writes a balance directly. The only
 * way to change one from here is to post an adjustment, which requires a
 * reason and produces a ledger entry and an audit record.
 */
#[Layout('components.layouts.app')]
class WalletDetail extends Component
{
    use WithPagination;

    public User $user;

    // Adjustment form
    public int $adjustmentAmount = 0;

    public string $adjustmentReason = '';

    public string $adjustmentSource = 'adjustment';

    public string $adjustmentExpiresAt = '';

    public bool $showAdjustment = false;

    /**
     * The most recent reconciliation result, as a plain array.
     *
     * Held in its serialized form rather than as a ReconciliationReport:
     * Livewire round-trips public properties through the browser, and a
     * readonly value object cannot survive that. The report is produced by
     * the reconciler and immediately flattened here.
     *
     * @var array<string, mixed>|null
     */
    public ?array $report = null;

    public function mount(User $user): void
    {
        $this->authorize('wallets.inspect');

        $this->user = $user;
    }

    public function adjust(AdjustCredits $action): void
    {
        $this->authorize('credits.adjust');

        $validated = $this->validate([
            'adjustmentAmount' => ['required', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'adjustmentReason' => ['required', 'string', 'min:5', 'max:500'],
            'adjustmentSource' => ['required', Rule::enum(CreditLotSource::class)],
            'adjustmentExpiresAt' => ['nullable', 'date', 'after:now'],
        ], [
            'adjustmentAmount.not_in' => 'An adjustment must add or remove credits.',
            'adjustmentReason.required' => 'A reason is required for every adjustment.',
            'adjustmentReason.min' => 'Give a reason someone reviewing this later will understand.',
        ]);

        try {
            $action->handle(
                user: $this->user,
                amount: $validated['adjustmentAmount'],
                reason: $validated['adjustmentReason'],
                actor: auth()->user(),
                source: CreditLotSource::from($validated['adjustmentSource']),
                expiresAt: $validated['adjustmentExpiresAt'] !== ''
                    ? new DateTimeImmutable($validated['adjustmentExpiresAt'])
                    : null,
            );
        } catch (DomainException $e) {
            $this->addError('adjustmentAmount', $e->getMessage());

            return;
        }

        $this->reset('adjustmentAmount', 'adjustmentReason', 'adjustmentExpiresAt', 'showAdjustment');

        session()->flash('status', 'Adjustment posted to the ledger.');
    }

    public function reconcile(CreditLedgerReconciler $reconciler, CreditLedgerService $credits): void
    {
        $this->authorize('wallets.reconcile');

        $report = $reconciler->reconcile($credits->walletFor($this->user));

        $this->report = $report->toArray();

        activity('wallet')
            ->performedOn($this->user)
            ->causedBy(auth()->user())
            ->withProperties($this->report)
            ->log('wallet_reconciled');
    }

    /**
     * @return Collection<int, CreditLot>
     */
    public function lots(int $walletId): Collection
    {
        return CreditLot::where('credit_wallet_id', $walletId)->orderByDesc('id')->get();
    }

    /**
     * @return LengthAwarePaginator<int, CreditTransaction>
     */
    public function creditTransactions(int $walletId): LengthAwarePaginator
    {
        return CreditTransaction::where('credit_wallet_id', $walletId)
            ->with('creator')
            ->orderByDesc('id')
            ->paginate(20, pageName: 'credits');
    }

    /**
     * @return LengthAwarePaginator<int, CashTransaction>
     */
    public function cashTransactions(int $walletId): LengthAwarePaginator
    {
        return CashTransaction::where('cash_wallet_id', $walletId)
            ->orderByDesc('id')
            ->paginate(20, pageName: 'cash');
    }

    public function render(CreditLedgerService $credits, CashLedgerService $cash): View
    {
        $creditWallet = $credits->walletFor($this->user);
        $cashWallet = $cash->walletFor($this->user);

        return view('livewire.admin.wallets.wallet-detail', [
            'creditWallet' => $creditWallet,
            'cashWallet' => $cashWallet,
            'spendableCredits' => $creditWallet->spendableBalance(),
            'expiredUnclaimed' => $creditWallet->expiredUnclaimedBalance(),
            'lots' => $this->lots($creditWallet->id),
            'creditTransactions' => $this->creditTransactions($creditWallet->id),
            'cashTransactions' => $this->cashTransactions($cashWallet->id),
            'sources' => CreditLotSource::cases(),
        ]);
    }
}

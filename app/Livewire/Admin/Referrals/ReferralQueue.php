<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Referrals;

use App\Domain\Referrals\Services\ReferralReconciler;
use App\Enums\ReferralStatus;
use App\Models\Referral;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Referrals, for staff.
 *
 * WHAT STAFF CANNOT DO HERE, and there is no method for any of it: grant
 * credits directly, set a reward amount, mark a purchase as qualifying,
 * reassign a referrer, or delete a rewarded referral. Each of those would be a
 * way to create credits without a purchase behind them, which is the whole risk
 * this stage has to contain.
 *
 * The one action offered is invalidating a referral that has not been paid --
 * refusing something before credits are issued. It records who decided and why,
 * and it is refused outright on a rewarded referral: credits in an immutable
 * ledger cannot be taken back by changing a status, and pretending otherwise
 * would be worse than not offering it.
 *
 * If a reward genuinely should be granted after being declined, it goes through
 * the ordinary reward path with its own idempotency and its own ledger entry --
 * not through a form on this screen.
 */
#[Layout('components.layouts.app')]
#[Title('Referrals')]
class ReferralQueue extends Component
{
    use WithPagination;

    /** A ReferralStatus value, or `all`. */
    #[Url]
    public string $filter = 'all';

    #[Url]
    public string $search = '';

    public string $invalidationReason = '';

    public ?int $invalidating = null;

    public function mount(): void
    {
        $this->authorize('referrals.view');
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startInvalidating(int $referralId): void
    {
        $this->authorize('referrals.manage');

        $this->invalidating = $referralId;
        $this->invalidationReason = '';
        $this->resetErrorBag('invalidation');
    }

    public function cancelInvalidating(): void
    {
        $this->invalidating = null;
        $this->invalidationReason = '';
    }

    /**
     * Refuse a referral before it is paid.
     *
     * Never a deletion: the relationship stays, with a reason and a name
     * attached, because somebody may need to explain the decision later.
     *
     * Refused on a rewarded referral by the status machine itself. The credits
     * are in the ledger and may already have been spent on bids that cannot be
     * unwound; a status change here would not take them back, and offering the
     * control would imply it did.
     */
    public function invalidate(): void
    {
        $this->authorize('referrals.manage');

        $referral = Referral::find($this->invalidating);

        if ($referral === null) {
            $this->cancelInvalidating();

            return;
        }

        if (trim($this->invalidationReason) === '') {
            $this->addError('invalidation', 'Say why this referral is not eligible.');

            return;
        }

        try {
            if (! $referral->status->canTransitionTo(ReferralStatus::Invalidated)) {
                throw new DomainException(
                    'A rewarded referral cannot be invalidated. The credits are already in the '
                    .'ledger and a status change here would not take them back.'
                );
            }

            $referral->status = ReferralStatus::Invalidated;
            $referral->invalidation_reason = mb_substr(trim($this->invalidationReason), 0, 500);
            $referral->invalidated_by = auth()->id();
            $referral->invalidated_at = Carbon::now();
            $referral->save();
        } catch (DomainException $e) {
            $this->addError('invalidation', $e->getMessage());

            return;
        }

        $this->cancelInvalidating();
    }

    public function render(ReferralReconciler $reconciler): View
    {
        return view('livewire.admin.referrals.referral-queue', [
            'referrals' => $this->referrals(),
            'summary' => $reconciler->summary(),
            'statuses' => ReferralStatus::cases(),
            // Local checks only, and read-only. Nothing on this screen repairs
            // anything, and nothing here issues a credit.
            'anomalies' => auth()->user()->can('referrals.manage')
                ? $reconciler->report(limit: 50)
                : [],
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Referral>
     */
    private function referrals(): LengthAwarePaginator
    {
        $status = ReferralStatus::tryFrom($this->filter);

        return Referral::query()
            ->with(['referrer', 'referred', 'qualifyingOrder', 'creditTransaction'])
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($this->search !== '', function (Builder $q): void {
                $term = '%'.mb_substr(trim($this->search), 0, 80).'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('code_used', 'like', $term)
                    ->orWhereHas('referrer', fn (Builder $u) => $u->where('name', 'like', $term))
                    ->orWhereHas('referred', fn (Builder $u) => $u->where('name', 'like', $term)));
            })
            ->latest('id')
            ->paginate(20);
    }
}

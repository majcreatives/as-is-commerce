<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Refunds;

use App\Domain\Refunds\Actions\ProcessRefund;
use App\Domain\Refunds\Services\RefundReconciler;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Refund;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Money owed, money going back, and money that would not go.
 *
 * TWO LISTS, AND THE FIRST IS THE POINT. `Recovery required` is the orders
 * Stages 7 and 8 deliberately left open -- a payment succeeded, nothing could
 * be delivered against it, and nobody has yet decided what to do. Until Stage
 * 10 there was no answer to give; this screen is where the answer gets given.
 * The other list is what happened to the refunds that followed.
 *
 * WHAT STAFF CANNOT DO HERE. There is no control to mark a refund succeeded,
 * to edit an amount, to change a provider reference, or to delete a failed
 * attempt -- and no method behind any of them. A refund succeeds when the
 * provider says it did and at no other moment, and a failed attempt stays
 * visible because it is part of what happened.
 *
 * Retrying is the one action offered, and it is not a retry of the old
 * attempt: it sends a refund still waiting for the provider. A refund that
 * failed is answered by a new request from the order, with its own record, so
 * the failure is never overwritten by the success that followed it.
 */
#[Layout('components.layouts.app')]
#[Title('Refunds')]
class RefundQueue extends Component
{
    use WithPagination;

    /** recovery | pending | processing | succeeded | failed | all */
    #[Url]
    public string $filter = 'recovery';

    public function mount(): void
    {
        $this->authorize('refunds.view');
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Send a refund that is still waiting for the provider.
     *
     * Only from `Pending` -- the action refuses anything else -- so this
     * cannot re-send a refund the provider has already accepted, which is the
     * one thing that could return money twice.
     */
    public function retry(int $refundId, ProcessRefund $process): void
    {
        $this->authorize('refunds.retry');
        $this->authorize('refunds.process');

        $refund = Refund::find($refundId);

        if ($refund === null) {
            return;
        }

        try {
            $process->handle($refund, auth()->user());
        } catch (DomainException $e) {
            $this->addError('retry', $e->getMessage());
        }
    }

    public function render(RefundReconciler $reconciler): View
    {
        return view('livewire.admin.refunds.refund-queue', [
            'recovery' => $this->filter === 'recovery' ? $this->recoveryQueue() : null,
            'refunds' => $this->filter === 'recovery' ? null : $this->refunds(),
            'recoveryCount' => $this->recoveryCount(),
            'failedCount' => Refund::query()->where('status', RefundStatus::Failed)->count(),
            // Local checks only. The provider comparison belongs to
            // `refunds:reconcile`, which is scheduled -- a page render is no
            // place to make a network call per row.
            'anomalies' => auth()->user()->can('refunds.inspect')
                ? $reconciler->report(limit: 50, askProvider: false)
                : [],
        ]);
    }

    /**
     * Orders waiting for somebody to decide what is owed.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    private function recoveryQueue(): LengthAwarePaginator
    {
        return $this->recoveryScope()
            ->with(['user', 'items'])
            ->latest('id')
            ->paginate(20);
    }

    private function recoveryCount(): int
    {
        return $this->recoveryScope()->count();
    }

    /**
     * Blocked orders with no refund started against them.
     *
     * @return Builder<Order>
     */
    private function recoveryScope()
    {
        return Order::query()
            ->blocked()
            ->whereDoesntHave('refunds', fn ($q) => $q->whereIn('status', [
                RefundStatus::Pending,
                RefundStatus::Processing,
                RefundStatus::Succeeded,
            ]));
    }

    /**
     * @return LengthAwarePaginator<int, Refund>
     */
    private function refunds(): LengthAwarePaginator
    {
        $status = RefundStatus::tryFrom($this->filter);

        return Refund::query()
            ->with(['order.user', 'requestedBy'])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(20);
    }
}

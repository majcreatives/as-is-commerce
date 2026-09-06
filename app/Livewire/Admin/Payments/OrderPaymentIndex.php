<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Payments;

use App\Enums\OrderPaymentStatus;
use App\Models\OrderPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What was asked of the payment provider, and what came back.
 *
 * ONE ATTEMPT PER ROW. A customer who abandons a payment page and returns gets
 * a new attempt with a new reference, and each keeps the amount it was actually
 * opened with -- which is what verification compares against. Several attempts
 * per order is normal; at most one ever reaches success.
 *
 * THERE IS NO "MARK PAID" HERE, AND THERE IS NO CODE PATH FOR ONE. An order
 * becomes paid only through a payment verified server-to-server with the
 * provider. A control here would have nothing to call, and offering one would
 * suggest a member of staff can assert that money arrived.
 *
 * NOTHING ON THIS SCREEN IS A CREDENTIAL. The provider reference identifies a
 * transaction, the channel says how somebody paid. No card detail, no
 * authorization code, no secret -- none of which this platform stores anyway.
 */
#[Layout('components.layouts.app')]
#[Title('Payments')]
class OrderPaymentIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('order_payments.view');
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        return view('livewire.admin.payments.order-payment-index', [
            'payments' => $this->payments(),
            'statuses' => OrderPaymentStatus::cases(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, OrderPayment>
     */
    private function payments(): LengthAwarePaginator
    {
        $status = OrderPaymentStatus::tryFrom($this->status);
        $term = mb_substr(trim($this->search), 0, 64);

        return OrderPayment::query()
            // Eager loaded: a page of twenty would otherwise ask for twenty
            // orders, twenty customers and twenty refund sets.
            ->with(['order.user', 'refunds'])
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('provider_reference', 'like', "%{$term}%")
                ->orWhereHas('order', fn (Builder $o) => $o->where('order_number', 'like', "%{$term}%"))))
            ->latest('id')
            ->paginate(25);
    }
}

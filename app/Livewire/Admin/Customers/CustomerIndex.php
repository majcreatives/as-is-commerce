<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Customers;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customers, for support.
 *
 * FINDING SOMEBODY, NOT BROWSING EVERYBODY. Support reaches this screen with a
 * name or a number from a call, so search is the point and the listing is the
 * fallback. Bounded and paginated in both cases.
 *
 * Phone numbers are normalized before matching, because they are stored E.164 --
 * somebody typing a number the way a customer says it out loud has to become
 * +233… before it will find anything.
 *
 * READ ONLY. There is nothing here that changes an account, a balance or an
 * order. Support looks; the domain services act, from the screens that own
 * them.
 */
#[Layout('components.layouts.app')]
#[Title('Customers')]
class CustomerIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('customers.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(PhoneNumberNormalizer $phones): View
    {
        return view('livewire.admin.customers.customer-index', [
            'customers' => $this->customers($phones),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    private function customers(PhoneNumberNormalizer $phones): LengthAwarePaginator
    {
        $term = mb_substr(trim($this->search), 0, 64);
        $e164 = $term === '' ? null : $phones->normalize($term);

        return User::query()
            ->withCount('orders')
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->when($e164 !== null, fn (Builder $p) => $p->orWhere('phone', $e164))
                ->orWhere('referral_code', mb_strtoupper($term))))
            ->latest('id')
            ->paginate(25);
    }
}

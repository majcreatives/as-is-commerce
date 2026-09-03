<?php

declare(strict_types=1);

namespace App\Livewire\Credits;

use App\Models\CreditPurchase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A customer's own credit purchases.
 *
 * Scoped to the signed-in user by the query itself rather than by a check on
 * each row, so there is no arrangement of parameters that returns someone
 * else's payments. Provider payloads are never shown here.
 */
#[Layout('components.layouts.app')]
#[Title('Credit purchases')]
class PurchaseHistory extends Component
{
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, CreditPurchase>
     */
    public function purchases(): LengthAwarePaginator
    {
        return CreditPurchase::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function render(): View
    {
        return view('livewire.credits.purchase-history', [
            'purchases' => $this->purchases(),
        ]);
    }
}

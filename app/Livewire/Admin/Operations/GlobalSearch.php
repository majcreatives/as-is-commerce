<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Operations;

use App\Domain\Operations\Queries\OperationsSearch;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One box for whatever reference somebody is holding.
 *
 * Support has a string -- an order number from an email, a provider reference
 * from a Paystack dashboard, a phone number from a call -- and should not have
 * to know which of eight screens it belongs to.
 *
 * BOUNDED, AND SERVER SIDE. The term is capped before it reaches a query and
 * each kind of result is limited. Nothing is loaded into the browser to be
 * filtered there: a search that shipped the customer table to Livewire would be
 * both slow and a disclosure.
 *
 * Results respect who is looking. Each group is rendered only for somebody
 * holding the permission that governs that kind of record, and every link goes
 * to a page that checks its own.
 */
#[Layout('components.layouts.app')]
#[Title('Search')]
class GlobalSearch extends Component
{
    #[Url]
    public string $q = '';

    public function mount(): void
    {
        $this->authorize('admin.dashboard.view');
    }

    public function render(OperationsSearch $search): View
    {
        $term = $search->clean($this->q);

        return view('livewire.admin.operations.global-search', [
            'term' => $term,
            // Two characters minimum: a single letter matches most of the
            // catalog and helps nobody.
            'tooShort' => $term !== '' && $search->isTooShort($term),
            'results' => $term === '' || $search->isTooShort($term)
                ? []
                : $search->search($term),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Operations;

use App\Domain\Operations\Queries\ExceptionCentre;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everything that needs a person, in one list.
 *
 * READ AND ROUTE. There is no control on this page that resolves anything, and
 * no "dismiss" or "acknowledge" -- an exception disappears when the situation it
 * describes stops being true, and marking one as handled without handling it
 * would defeat the only purpose the screen has.
 *
 * Every row links to the screen that owns the record, where the action lives
 * behind the permission that governs it. Refunding is on the order. Retrying a
 * delivery is on the order. Nothing is duplicated here.
 *
 * PROVIDER CHECKS ARE OPT-IN. Reconciling a refund against Paystack means a
 * network call per refund, so the page loads without them and offers a button.
 * A screen that quietly made fifty API calls on every render would be unusable
 * on the day it mattered.
 */
#[Layout('components.layouts.app')]
#[Title('Exceptions')]
class ExceptionCentrePage extends Component
{
    #[Url]
    public string $category = '';

    /** Whether to check refunds against the provider on this render. */
    public bool $askProviders = false;

    public function mount(): void
    {
        $this->authorize('exceptions.view');
    }

    public function checkProviders(): void
    {
        $this->authorize('exceptions.view');

        $this->askProviders = true;
    }

    public function render(ExceptionCentre $centre): View
    {
        return view('livewire.admin.operations.exception-centre-page', [
            'exceptions' => $centre->all(
                category: $this->category === '' ? null : $this->category,
                askProviders: $this->askProviders,
            ),
            'counts' => $centre->counts($this->askProviders),
        ]);
    }
}

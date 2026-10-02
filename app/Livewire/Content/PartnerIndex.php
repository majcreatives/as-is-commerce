<?php

declare(strict_types=1);

namespace App\Livewire\Content;

use App\Domain\Marketplace\Queries\ContentDiscoveryQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public partners page.
 *
 * PUBLISHED PARTNERS ONLY, through {@see ContentDiscoveryQuery}, so a record
 * that an administrator added but has not published cannot appear here by
 * merely being present in the table. Adding is not publishing.
 *
 * WHAT THIS PAGE MAY SAY. A partner is a business the platform says it works
 * with, which is a claim about a third party. The page therefore shows only
 * what an administrator entered and adds nothing of its own: no "trusted by",
 * no endorsement language, no invented partnership. If nobody has been
 * published yet, the page says that rather than showing examples.
 */
#[Layout('components.layouts.app')]
#[Title('Partners')]
class PartnerIndex extends Component
{
    use WithPagination;

    public function render(ContentDiscoveryQuery $content): View
    {
        return view('livewire.content.partner-index', [
            'partners' => $content->partners(),
        ])->layoutData([
            'description' => 'Businesses that work with As-Is Commerce.',
        ]);
    }
}

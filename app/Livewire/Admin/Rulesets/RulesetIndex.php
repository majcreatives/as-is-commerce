<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Rulesets;

use App\Domain\Auction\Actions\ActivateRuleset;
use App\Domain\Auction\Actions\ArchiveRuleset;
use App\Domain\Auction\Actions\CreateRulesetVersion;
use App\Models\AuctionRuleset;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Auction rulesets')]
class RulesetIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('auction_rulesets.view');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function activate(int $rulesetId, ActivateRuleset $action): void
    {
        $this->authorize('auction_rulesets.activate');

        $ruleset = AuctionRuleset::findOrFail($rulesetId);

        try {
            $action->handle($ruleset, auth()->user());
        } catch (DomainException $e) {
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        session()->flash('status', "Ruleset [{$ruleset->name} v{$ruleset->version}] is now active.");
    }

    public function archive(int $rulesetId, ArchiveRuleset $action): void
    {
        $this->authorize('auction_rulesets.archive');

        $ruleset = AuctionRuleset::findOrFail($rulesetId);

        try {
            $action->handle($ruleset, auth()->user());
        } catch (DomainException $e) {
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        session()->flash('status', "Ruleset [{$ruleset->name} v{$ruleset->version}] has been archived.");
    }

    public function draftNewVersion(int $rulesetId, CreateRulesetVersion $action): void
    {
        $this->authorize('auction_rulesets.create');

        $source = AuctionRuleset::findOrFail($rulesetId);
        $draft = $action->handle($source, auth()->user());

        $this->redirectRoute('admin.rulesets.edit', ['ruleset' => $draft->id], navigate: true);
    }

    /**
     * @return LengthAwarePaginator<int, AuctionRuleset>
     */
    public function rulesets(): LengthAwarePaginator
    {
        return AuctionRuleset::query()
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderBy('name')
            ->orderByDesc('version')
            ->paginate(15);
    }

    public function render(): View
    {
        return view('livewire.admin.rulesets.ruleset-index', [
            'rulesets' => $this->rulesets(),
        ]);
    }
}

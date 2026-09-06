<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Operations;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * Who did what, from the activity log that already exists.
 *
 * EXPOSED, NOT REBUILT. The platform has recorded administrative actions since
 * the settings stage; this is a window onto those records rather than a second
 * audit system. A second one would disagree with the first eventually, and
 * nobody would know which to believe.
 *
 * APPEND-ONLY, AND THERE IS NOTHING HERE THAT COULD CHANGE THAT. No edit, no
 * delete, no bulk action -- an audit trail an administrator can tidy is not an
 * audit trail. The only thing this screen does is read.
 *
 * Model-event diffs land in `attribute_changes`; `properties` holds what an
 * action attached deliberately. Both are shown, because the interesting detail
 * is sometimes in one and sometimes in the other.
 */
#[Layout('components.layouts.app')]
#[Title('Audit log')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url]
    public string $log = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('log', 'search', 'from', 'to');
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.operations.audit-log', [
            'entries' => $this->entries(),
            // The log names actually in use, so the filter offers nothing
            // that would return an empty page.
            'logs' => Activity::query()
                ->select('log_name')
                ->whereNotNull('log_name')
                ->distinct()
                ->orderBy('log_name')
                ->pluck('log_name')
                ->all(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Activity>
     */
    private function entries(): LengthAwarePaginator
    {
        $term = mb_substr(trim($this->search), 0, 80);

        return Activity::query()
            ->with(['causer', 'subject'])
            ->when($this->log !== '', fn (Builder $q) => $q->where('log_name', $this->log))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('description', 'like', "%{$term}%")
                ->orWhere('subject_type', 'like', "%{$term}%")))
            ->when($this->from !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest('id')
            ->paginate(25);
    }
}

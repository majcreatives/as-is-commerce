<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Operations;

use App\Domain\Operations\Queries\ExceptionCentre;
use App\Domain\Operations\Queries\OperationsMetrics;
use App\Domain\Operations\Queries\SchedulerStatus;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What the platform looks like right now.
 *
 * REAL FIGURES OR NONE. Every number is counted from the table that owns it,
 * at the moment the page renders. There is no metrics table, no cached counter
 * and no rollup job, because an operations screen exists for the moments when
 * somebody needs to know what is actually true -- and a counter that can drift
 * is worse than no counter at all.
 *
 * IT REPORTS AND ROUTES. Nothing on this page changes anything; every figure
 * links to the screen that owns the records behind it, where the actions live
 * behind their own permissions.
 *
 * The exception counts come without asking any payment provider, so opening the
 * dashboard makes no network calls. The exception centre itself can ask, and
 * says when it did.
 */
#[Layout('components.layouts.app')]
#[Title('Operations')]
class OperationsDashboard extends Component
{
    public function mount(): void
    {
        $this->authorize('admin.dashboard.view');
    }

    public function render(OperationsMetrics $metrics, ExceptionCentre $exceptions, SchedulerStatus $scheduler): View
    {
        return view('livewire.admin.operations.operations-dashboard', [
            'metrics' => $metrics->all(),
            'collected' => $metrics->collected(),
            'refunded' => $metrics->refunded(),
            // Local only. A dashboard that made provider calls on every render
            // would be slow exactly when it is needed most.
            'exceptions' => auth()->user()->can('exceptions.view')
                ? $exceptions->counts()
                : [],
            'sweeps' => $scheduler->all(),
        ]);
    }
}

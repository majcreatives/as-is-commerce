<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Notifications;

use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notification delivery, for staff.
 *
 * READ-ONLY, AND NARROW. Staff can see that a message was sent, to whom, on
 * which channel, and why it failed. They cannot edit one, resend one as
 * somebody else, delete history, or reach a business event through it -- a
 * notification describes something that happened, and rewriting the
 * description would not change the event but would make the record lie.
 *
 * The default view is failures, because that is the only thing here anybody
 * needs to act on: a delivered notification is not news.
 *
 * A failed email is not a failed business event. The in-app notification is
 * there, and the transaction behind it committed long before.
 */
#[Layout('components.layouts.app')]
#[Title('Notifications')]
class NotificationIndex extends Component
{
    use WithPagination;

    /** failed | all */
    #[Url]
    public string $filter = 'failed';

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        $this->authorize('notifications.inspect');
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.notifications.notification-index', [
            'notifications' => $this->notifications(),
            'types' => NotificationType::cases(),
            'failedCount' => Notification::query()->mailFailed()->count(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    private function notifications(): LengthAwarePaginator
    {
        return Notification::query()
            ->with('notifiable')
            ->when($this->filter === 'failed', fn ($q) => $q->mailFailed())
            ->when($this->type !== '', fn ($q) => $q->where('event_type', $this->type))
            ->latest()
            ->paginate(25);
    }
}

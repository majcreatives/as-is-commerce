<?php

declare(strict_types=1);

namespace App\Livewire\Notifications;

use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A customer's own notifications.
 *
 * SCOPED IN THE QUERY, NOT AFTER IT. Every read starts from the signed-in
 * user's own relation, so there is no path by which somebody else's
 * notification could be listed, counted, or marked read. Marking one read
 * re-queries within that same scope rather than trusting the id it was handed.
 *
 * Paginated because a busy bidder accumulates these quickly, and the unread
 * badge counts against an index rather than loading rows it will not show.
 */
#[Layout('components.layouts.app')]
#[Title('Notifications')]
class NotificationCentre extends Component
{
    use WithPagination;

    /** all | unread */
    #[Url]
    public string $filter = 'all';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Mark one notification read.
     *
     * Resolved through the user's own relation. An id belonging to somebody
     * else simply finds nothing -- there is no branch that could act on it.
     */
    public function markRead(string $id): void
    {
        auth()->user()->notifications()
            ->whereKey($id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function render(): View
    {
        return view('livewire.notifications.notification-centre', [
            'notifications' => $this->notifications(),
            'unreadCount' => auth()->user()->unreadNotificationCount(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    private function notifications(): LengthAwarePaginator
    {
        return auth()->user()->notifications()
            ->when($this->filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->latest()
            ->paginate(20);
    }
}

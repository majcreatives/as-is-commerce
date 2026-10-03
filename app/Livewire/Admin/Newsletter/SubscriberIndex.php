<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Newsletter;

use App\Enums\NewsletterStatus;
use App\Models\NewsletterSubscriber;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The newsletter list, read-only.
 *
 * AGENTS.md §70: a read-only screen stays read-only. So there is no delete here,
 * no resend button, and no "unsubscribe on their behalf". A subscriber is removed
 * by the person who asked, through the link in their own email, and an
 * administrator editing the row would both destroy the consent record and mean a
 * removal was never actually requested. The only way this screen can be wrong
 * about somebody is if they mis-typed an address into the form, which is theirs
 * to resolve.
 *
 * THE COUNTS ARE THE USEFUL PART. Subscribed is the number that would mean
 * something to anybody; pending is the number that says confirmation emails are
 * not arriving, which is the failure mode here and one nothing else would show.
 *
 * NO EXPORT, and this is a considered omission rather than an unfinished feature.
 * A CSV of this list is the complete set of every address somebody gave us, and
 * adding "download the addresses" is a much larger decision than "look at the
 * list". If it is ever wanted it should be a separately-permissioned action that
 * writes an audit entry.
 */
class SubscriberIndex extends Component
{
    use WithPagination;

    #[Layout('components.layouts.app')]
    #[Title('Newsletter')]

    /**
     * Which statuses to show. Kept in the query string so a staff member can
     * send somebody a link to "the ones who never confirmed" without the page
     * changing meaning when they navigate.
     */
    #[Url(as: 'status', except: 'subscribed')]
    public string $filter = 'subscribed';

    /** Free-text search over the address only. Bounded and escaped below. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('newsletter.view');
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.newsletter.subscriber-index', [
            'subscribers' => $this->query()->paginate(25),
            'subscribedCount' => NewsletterSubscriber::query()->subscribed()->count(),
            'pendingCount' => NewsletterSubscriber::query()->pending()->count(),
            'unsubscribedCount' => NewsletterSubscriber::query()
                ->where('status', NewsletterStatus::Unsubscribed->value)
                ->count(),
        ]);
    }

    /**
     * @return Builder<NewsletterSubscriber>
     */
    private function query(): Builder
    {
        $query = NewsletterSubscriber::query()->latest('created_at');

        // A crafted ?status= falls back to the default rather than producing an
        // empty list that looks like "these are all the subscribers" when it is
        // really "you asked for a status that does not exist".
        $filter = in_array($this->filter, ['all', 'pending', 'subscribed', 'unsubscribed'], true)
            ? $this->filter
            : 'subscribed';

        $query->when(
            $filter !== 'all',
            fn (Builder $q): Builder => $q->where('status', $filter),
        );

        // Escaped by the binding, so this cannot become SQL, and constrained to a
        // prefix match on the address so it uses the unique index rather than
        // scanning every row on each keystroke.
        //
        // Backslash FIRST: escaping it afterwards would double the backslashes
        // this function just inserted in front of the wildcards, and the pattern
        // would stop matching what the person typed.
        $term = trim($this->search);

        if ($term !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($term));

            $query->where('email', 'like', $escaped.'%');
        }

        return $query;
    }
}

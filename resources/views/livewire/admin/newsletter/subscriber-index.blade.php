{{-- The newsletter list. Read-only by design.

     There is no delete, no resend and no "unsubscribe this person" control here,
     and their absence is the screen's most important feature: a subscriber is
     removed by them, through the link in their own email. See
     App\Livewire\Admin\Newsletter\SubscriberIndex. --}}

<div>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Newsletter</h1>
            <p class="mt-1 text-sm text-slate-500">
                Addresses people asked to hear from us, and whether they confirmed
                they own them. This list can be read but not edited or exported.
            </p>
        </div>
    </div>

    {{-- The counts are the point of the screen. "Pending" is not a backlog to
         clear; it is the visible symptom of confirmation emails not arriving,
         which is the one thing wrong with a self-hosted newsletter list. --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Subscribed</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ $subscribedCount }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Awaiting confirmation</p>
            <p class="mt-1 text-2xl font-bold text-amber-700">{{ $pendingCount }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Unsubscribed</p>
            <p class="mt-1 text-2xl font-bold text-slate-700">{{ $unsubscribedCount }}</p>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <label for="newsletter-search" class="sr-only">Search by address</label>
        <input
            id="newsletter-search"
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search by address"
            class="w-full max-w-xs rounded-lg border-slate-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500"
        >

        <label for="newsletter-status" class="sr-only">Filter by status</label>
        <select
            id="newsletter-status"
            wire:model.live="filter"
            @class([
                'rounded-lg border-slate-300 text-sm shadow-sm focus:border-accent-500 focus:ring-accent-500',
                'border-red-400' => $errors->has('filter'),
            ])
        >
            <option value="subscribed">Subscribed</option>
            <option value="pending">Awaiting confirmation</option>
            <option value="unsubscribed">Unsubscribed</option>
            <option value="all">All</option>
        </select>
    </div>

    <div class="mt-4 overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead>
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th scope="col" class="py-2 pr-4">Address</th>
                    <th scope="col" class="py-2 pr-4">Status</th>
                    <th scope="col" class="py-2 pr-4">Confirmed</th>
                    <th scope="col" class="py-2 pr-4">Signed up</th>
                    <th scope="col" class="py-2 pr-4">Source</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($subscribers as $subscriber)
                    <tr>
                        <td class="py-2 pr-4 font-mono text-xs text-slate-700">{{ $subscriber->email }}</td>
                        <td class="py-2 pr-4">
                            <span @class([
                                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                $subscriber->status->badgeClasses(),
                            ])>
                                {{ $subscriber->status->label() }}
                            </span>
                        </td>
                        <td class="py-2 pr-4 text-slate-500">
                            {{ $subscriber->consented_at?->format('j M Y, H:i') ?? '—' }}
                        </td>
                        <td class="py-2 pr-4 text-slate-500">
                            {{ $subscriber->created_at->format('j M Y') }}
                        </td>
                        <td class="py-2 pr-4 text-slate-500">{{ $subscriber->source ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-8 text-center text-slate-500">
                            {{ $search !== '' || $filter !== 'all'
                                ? 'Nobody matches this filter.'
                                : 'Nobody has signed up yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $subscribers->links() }}</div>
</div>
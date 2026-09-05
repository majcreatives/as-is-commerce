{{-- Notification delivery, for staff.

     Read-only. There is no control here to edit, resend or delete a
     notification, and none to reach the business event behind one. --}}

<div>
    <x-admin.nav />

    <x-page-header
        title="Notifications"
        description="What the platform told people, and anything that failed to reach them." />

    @if ($failedCount > 0)
        <x-alert variant="warning" class="mb-6">
            <strong>{{ $failedCount }}</strong>
            {{ Str::plural('email', $failedCount) }} could not be delivered. The in-app
            notification reached the customer either way, and the business event behind it
            completed normally.
        </x-alert>
    @endif

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-48">
            <x-field label="Show" name="filter">
                <select wire:model.live="filter" id="filter"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="failed">Failed deliveries</option>
                    <option value="all">Everything</option>
                </select>
            </x-field>
        </div>

        <div class="w-full sm:w-64">
            <x-field label="Type" name="type">
                <select wire:model.live="type" id="type"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="">All types</option>
                    @foreach ($types as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>
    </div>

    <x-card :padded="false">
        @if ($notifications->isEmpty())
            <div class="p-5">
                <x-empty-state
                    title="Nothing to show"
                    description="No notification matches this filter." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Type</th>
                            <th class="px-5 py-3 font-semibold">Recipient</th>
                            <th class="px-5 py-3 font-semibold">In app</th>
                            <th class="px-5 py-3 font-semibold">Email</th>
                            <th class="px-5 py-3 font-semibold">Sent</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($notifications as $notification)
                            <tr class="{{ $notification->mailFailed() ? 'bg-amber-50/60' : '' }}">
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-slate-900">{{ $notification->title }}</p>
                                    <p class="text-xs text-slate-500">{{ $notification->event_type }}</p>
                                </td>

                                {{-- The name only. A staff listing is not a place to
                                     surface somebody's phone number or address. --}}
                                <td class="px-5 py-3 text-slate-700">
                                    {{ $notification->notifiable?->name ?? 'Removed account' }}
                                </td>

                                <td class="px-5 py-3">
                                    <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-200">
                                        Delivered
                                    </x-badge>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $notification->isUnread() ? 'Unread' : 'Read' }}
                                    </p>
                                </td>

                                <td class="px-5 py-3">
                                    @if ($notification->mail_status === null)
                                        <span class="text-xs text-slate-500">Not applicable</span>
                                    @elseif ($notification->mailFailed())
                                        <x-badge classes="bg-red-50 text-red-800 ring-red-200">Failed</x-badge>
                                        <p class="mt-1 max-w-xs text-xs text-slate-600">
                                            {{ $notification->mail_failure_reason }}
                                        </p>
                                    @else
                                        <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-200">
                                            Sent
                                        </x-badge>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-slate-500">
                                    {{ $notification->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-5 py-4">{{ $notifications->links() }}</div>
        @endif
    </x-card>
</div>

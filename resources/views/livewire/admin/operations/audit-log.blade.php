{{-- Who did what.

     A window onto the activity log the platform has been writing since the
     settings stage, not a second audit system. There is no edit control, no
     delete control and no bulk action on this page: an audit trail an
     administrator can tidy is not an audit trail. --}}

<div>
    <x-admin.nav />

    <x-page-header
        title="Audit log"
        description="Administrative actions, as they were recorded. Read-only, and append-only underneath." />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-56">
            <x-field label="Log" name="log">
                <select wire:model.live="log" id="log"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="">Every log</option>
                    @foreach ($logs as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <div class="w-full sm:w-64">
            <x-field label="Description or subject" name="search">
                <input type="search" id="search" wire:model.live.debounce.400ms="search" maxlength="80"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
            </x-field>
        </div>

        <div class="w-full sm:w-40">
            <x-field label="From" name="from">
                <input type="date" id="from" wire:model.live="from"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
            </x-field>
        </div>

        <div class="w-full sm:w-40">
            <x-field label="To" name="to">
                <input type="date" id="to" wire:model.live="to"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
            </x-field>
        </div>

        <x-button variant="ghost" size="sm" wire:click="clearFilters" class="mb-1">Clear</x-button>
    </div>

    <x-card :padded="false">
        @if ($entries->isEmpty())
            <div class="p-5">
                <x-empty-state
                    title="Nothing recorded"
                    description="No administrative action matches these filters." />
            </div>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($entries as $entry)
                    <li class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($entry->log_name)
                                        <x-badge>{{ $entry->log_name }}</x-badge>
                                    @endif
                                    <span class="font-semibold text-slate-900">{{ $entry->description }}</span>
                                    @if ($entry->event)
                                        <span class="font-mono text-xs text-slate-400">{{ $entry->event }}</span>
                                    @endif
                                </div>

                                <p class="mt-1 text-xs text-slate-500">
                                    {{-- The name only. Who acted is the audit
                                         question; their contact details are not. --}}
                                    {{ $entry->causer?->name ?? 'System' }}
                                    @if ($entry->subject_type)
                                        &middot; {{ class_basename($entry->subject_type) }}
                                        @if ($entry->subject_id) #{{ $entry->subject_id }} @endif
                                    @endif
                                </p>

                                @php
                                    // Model-event diffs land in attribute_changes;
                                    // properties holds what an action attached
                                    // deliberately with withProperties(). In v5
                                    // these are two separate columns, and the
                                    // interesting detail is sometimes in one and
                                    // sometimes in the other, so both are offered.
                                    $changes = $entry->attribute_changes?->toArray() ?? [];
                                    $attached = $entry->properties?->toArray() ?? [];
                                @endphp

                                @if ($changes !== [] || $attached !== [])
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-semibold text-brand-800 hover:underline">
                                            Detail
                                        </summary>

                                        @if ($changes !== [])
                                            <div class="mt-2">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Changed</p>
                                                <pre class="mt-1 overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-700">{{ json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        @endif

                                        @if ($attached !== [])
                                            <div class="mt-2">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Recorded with the action</p>
                                                <pre class="mt-1 overflow-x-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-700">{{ json_encode($attached, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </div>
                                        @endif
                                    </details>
                                @endif
                            </div>

                            <span class="shrink-0 text-xs text-slate-500">
                                {{ $entry->created_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-slate-100 px-5 py-4">{{ $entries->links() }}</div>
        @endif
    </x-card>

    <p class="mt-6 text-xs text-slate-500">
        Entries are never edited or removed, and nothing on this screen could do either.
        A correction is a new action with its own entry.
    </p>
</div>

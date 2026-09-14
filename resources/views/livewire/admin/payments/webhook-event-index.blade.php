<x-admin.shell>

    <x-page-header
        title="Payment events"
        description="Webhook deliveries from the payment provider. Payloads are shown with card and authorization details removed." />

    <div class="mb-4">
        <select wire:model.live="status"
                class="rounded-lg border-0 bg-white py-2 pl-3 pr-8 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600">
            <option value="">All statuses</option>
            @foreach ($statuses as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[52rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Received</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Event</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Purchase</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Payload</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($events as $event)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                {{ $event->received_at->format('j M Y, H:i:s') }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $event->event_type }}</span>
                                <span class="block font-mono text-xs text-slate-400">{{ $event->provider_event_id }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                {{ $event->purchase?->provider_reference ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :classes="$event->processing_status->badgeClasses()">
                                    {{ $event->processing_status->label() }}
                                </x-badge>
                                @if ($event->processing_error)
                                    <span class="mt-1 block max-w-xs text-xs text-red-700">
                                        {{ $event->processing_error }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-button wire:click="toggle({{ $event->id }})" variant="ghost" size="sm">
                                    {{ $expandedId === $event->id ? 'Hide' : 'View' }}
                                </x-button>
                            </td>
                        </tr>

                        @if ($expandedId === $event->id)
                            <tr class="bg-slate-50/60">
                                <td colspan="5" class="px-4 py-3">
                                    <pre class="max-h-80 overflow-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100">{{ json_encode($event->redactedPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10">
                                <x-empty-state
                                    title="No payment events"
                                    description="Verified webhook deliveries from the payment provider will appear here." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($events->hasPages())
        <div class="mt-4">{{ $events->links() }}</div>
    @endif
</x-admin.shell>

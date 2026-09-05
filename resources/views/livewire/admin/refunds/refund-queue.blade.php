{{-- Money owed, money going back, and money that would not go.

     Read-only except for one action. There is no control here to mark a refund
     succeeded, edit an amount, or delete a failed attempt — a refund succeeds
     when the provider says it did, and a failure stays visible because it is
     part of what happened. --}}

<div>
    <x-admin.nav />

    <x-page-header
        title="Refunds &amp; recovery"
        description="Payments the platform took and could not deliver against, and what was done about them." />

    @error('retry')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    @if ($recoveryCount > 0)
        <x-alert variant="warning" class="mb-6">
            <strong>{{ $recoveryCount }}</strong>
            {{ Str::plural('order', $recoveryCount) }}
            {{ $recoveryCount === 1 ? 'is' : 'are' }} waiting for somebody to decide what is owed.
            The payments are real and recorded; the platform will not return anything on its own.
        </x-alert>
    @endif

    @if (count($anomalies) > 0)
        <x-alert variant="danger" class="mb-6">
            <p class="font-semibold">
                {{ count($anomalies) }} reconciliation
                {{ Str::plural('anomaly', count($anomalies)) }}.
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($anomalies as $anomaly)
                    <li>
                        <span class="font-mono text-xs">{{ $anomaly['type'] }}</span>
                        — {{ $anomaly['detail'] }}
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-sm">
                Nothing has been changed. Reconciliation reports; a person decides, and fixes it
                with a new financial act.
            </p>
        </x-alert>
    @endif

    <div class="mb-6 flex flex-wrap items-center gap-2">
        @php
            $tabs = [
                'recovery' => 'Recovery required',
                'pending' => 'Pending',
                'processing' => 'Processing',
                'succeeded' => 'Succeeded',
                'failed' => 'Failed',
                'all' => 'All refunds',
            ];
        @endphp

        @foreach ($tabs as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition
                           {{ $filter === $value ? 'bg-brand-700 text-white' : 'text-brand-800 hover:bg-brand-50' }}">
                {{ $label }}
                @if ($value === 'recovery' && $recoveryCount > 0)
                    <span class="ml-1 rounded-full bg-white/20 px-1.5 text-xs">{{ $recoveryCount }}</span>
                @endif
                @if ($value === 'failed' && $failedCount > 0)
                    <span class="ml-1 rounded-full bg-white/20 px-1.5 text-xs">{{ $failedCount }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($recovery !== null)
        @if ($recovery->isEmpty())
            <x-empty-state
                title="Nothing awaiting recovery"
                description="Every payment the platform took has either been delivered against or dealt with." />
        @else
            <x-card :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Order</th>
                                <th class="px-5 py-3 font-semibold">Customer</th>
                                <th class="px-5 py-3 font-semibold">Source</th>
                                <th class="px-5 py-3 font-semibold">Paid</th>
                                <th class="px-5 py-3 font-semibold">Why it is stuck</th>
                                <th class="px-5 py-3 font-semibold">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($recovery as $order)
                                <tr>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                           class="font-semibold text-brand-800 underline">
                                            {{ $order->order_number }}
                                        </a>
                                        <span class="mt-1 block">
                                            <x-badge :classes="$order->status->badgeClasses()">
                                                {{ $order->status->label() }}
                                            </x-badge>
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-slate-700">{{ $order->user?->name }}</td>
                                    <td class="px-5 py-3">
                                        <x-badge :classes="$order->source->badgeClasses()">
                                            {{ $order->source->label() }}
                                        </x-badge>
                                    </td>
                                    <td class="px-5 py-3 tabular-nums font-semibold text-slate-900">
                                        <x-money :amount="$order->total()" />
                                    </td>
                                    <td class="max-w-md px-5 py-3 text-slate-600">
                                        {{ $order->fulfilment_blocked_reason }}
                                    </td>
                                    <td class="px-5 py-3 text-slate-500">
                                        {{ $order->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>

            <div class="mt-6">{{ $recovery->links() }}</div>
        @endif
    @else
        @if ($refunds->isEmpty())
            <x-empty-state
                title="No refunds here"
                description="Nothing matching this filter has been refunded." />
        @else
            <x-card :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Order</th>
                                <th class="px-5 py-3 font-semibold">Customer</th>
                                <th class="px-5 py-3 font-semibold">Amount</th>
                                <th class="px-5 py-3 font-semibold">Status</th>
                                <th class="px-5 py-3 font-semibold">Reason</th>
                                <th class="px-5 py-3 font-semibold">Requested by</th>
                                <th class="px-5 py-3 font-semibold">Provider</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($refunds as $refund)
                                <tr>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('admin.orders.show', $refund->order) }}" wire:navigate
                                           class="font-semibold text-brand-800 underline">
                                            {{ $refund->order->order_number }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-slate-700">{{ $refund->order->user?->name }}</td>
                                    <td class="px-5 py-3 tabular-nums font-semibold text-slate-900">
                                        <x-money :amount="$refund->amount()" />
                                    </td>
                                    <td class="px-5 py-3">
                                        <x-badge :classes="$refund->status->badgeClasses()">
                                            {{ $refund->status->label() }}
                                        </x-badge>
                                        @if ($refund->failure_reason)
                                            <span class="mt-1 block max-w-xs text-xs text-red-700">
                                                {{ $refund->failure_reason }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-slate-600">{{ $refund->reason->label() }}</td>
                                    <td class="px-5 py-3 text-slate-600">
                                        {{ $refund->requestedBy?->name ?? '—' }}
                                    </td>
                                    <td class="px-5 py-3 font-mono text-xs text-slate-500">
                                        {{ $refund->provider_reference ?? '—' }}
                                        @if ($refund->provider_status)
                                            <span class="block">{{ $refund->provider_status }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        {{-- Only for a refund the provider has not been told
                                             about. Re-sending one it already accepted is the
                                             one action that could return money twice. --}}
                                        @if ($refund->isAwaitingProvider())
                                            @can('refunds.retry')
                                                <x-button size="sm" variant="ghost"
                                                          wire:click="retry({{ $refund->id }})"
                                                          wire:loading.attr="disabled">
                                                    Send to provider
                                                </x-button>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>

            <div class="mt-6">{{ $refunds->links() }}</div>
        @endif
    @endif

    <p class="mt-8 text-xs text-slate-500">
        A refund returns cedis only. Auction bid credits are consumed permanently when a bid is
        accepted and are never restored, and a refund puts no stock back on sale.
    </p>
</div>

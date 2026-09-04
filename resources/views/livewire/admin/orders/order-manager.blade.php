{{-- Every order, for staff. Read-only: there is no control here that changes
     an amount, and none anywhere that marks an order paid. --}}

<div>
    <x-admin.nav />

    <x-page-header
        title="Orders"
        description="Every purchase obligation, and what happened to it." />

    @if ($blockedCount > 0)
        {{-- The operational queue that matters: money in, nothing delivered. --}}
        <x-alert variant="warning" class="mb-6">
            <strong>{{ $blockedCount }}</strong>
            {{ Str::plural('order', $blockedCount) }} were paid but could not be completed —
            somebody else acquired the item while the customer was paying. Each needs a decision.

            <button type="button" wire:click="$set('blocked', true)"
                    class="ml-1 font-semibold underline">Show them</button>
        </x-alert>
    @endif

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-64">
            <x-field label="Search" name="search">
                <x-input wire:model.live.debounce.300ms="search"
                         placeholder="Order number, customer or product" />
            </x-field>
        </div>

        <div class="w-full sm:w-48">
            <x-field label="Status" name="status">
                <select wire:model.live="status" id="status"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <div class="w-full sm:w-44">
            <x-field label="Source" name="source">
                <select wire:model.live="source" id="source"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="">All sources</option>
                    @foreach ($sources as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <label class="flex items-center gap-2 pb-2 text-sm text-slate-700">
            <input type="checkbox" wire:model.live="blocked"
                   class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
            Needs attention only
        </label>
    </div>

    <x-card :padded="false">
        @if ($orders->isEmpty())
            <div class="p-5">
                <x-empty-state
                    title="No orders match"
                    description="Nothing is listed here that has not actually been placed." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Order</th>
                            <th class="px-5 py-3 font-semibold">Customer</th>
                            <th class="px-5 py-3 font-semibold">Item</th>
                            <th class="px-5 py-3 font-semibold">Source</th>
                            <th class="px-5 py-3 font-semibold">Total</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($orders as $order)
                            <tr class="{{ $order->isFulfilmentBlocked() ? 'bg-amber-50/60' : '' }}">
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-slate-900">{{ $order->order_number }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $order->placed_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                    </p>
                                </td>

                                <td class="px-5 py-3 text-slate-700">{{ $order->user?->name }}</td>

                                <td class="px-5 py-3 text-slate-700">
                                    {{ $order->item()?->product_name_snapshot ?? '—' }}
                                </td>

                                <td class="px-5 py-3">
                                    <x-badge :classes="$order->source->badgeClasses()">
                                        {{ $order->source->label() }}
                                    </x-badge>

                                    @if ($order->endedAnAuction())
                                        <p class="mt-1 text-xs text-slate-500">ended auction #{{ $order->auction_id }}</p>
                                    @elseif ($order->auction_id)
                                        <p class="mt-1 text-xs text-slate-500">auction #{{ $order->auction_id }}</p>
                                    @endif
                                </td>

                                <td class="px-5 py-3 font-semibold tabular-nums text-slate-900">
                                    <x-money :amount="$order->total()" />
                                </td>

                                <td class="px-5 py-3">
                                    <x-badge :classes="$order->status->badgeClasses()">
                                        {{ $order->status->label() }}
                                    </x-badge>

                                    @if ($order->isFulfilmentBlocked())
                                        <p class="mt-1 text-xs font-semibold text-amber-800">
                                            needs attention
                                        </p>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-right">
                                    <x-button variant="ghost" size="sm"
                                              href="{{ route('admin.orders.show', $order) }}" wire:navigate>
                                        Open
                                    </x-button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-5 py-4">{{ $orders->links() }}</div>
        @endif
    </x-card>
</div>

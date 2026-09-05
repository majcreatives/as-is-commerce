{{-- The warehouse's own screen.

     Read and route, not act. Every actual move happens on the order's detail
     screen, where whoever is doing it can see the address, the customer and
     what was paid before touching anything — dispatching the wrong package off
     a list is exactly the mistake a manual process makes. --}}

<div>
    <x-admin.nav />

    <x-page-header
        title="Fulfilment"
        description="What needs packing, what is out, and what came back." />

    @php
        $tabs = [
            'outstanding' => 'All outstanding',
            'awaiting_address' => 'Awaiting address',
            'pending' => 'Pending',
            'preparing' => 'Preparing',
            'ready_for_dispatch' => 'Ready for dispatch',
            'dispatched' => 'Dispatched',
            'out_for_delivery' => 'Out for delivery',
            'delivery_failed' => 'Failed',
            'delivered' => 'Delivered',
        ];
    @endphp

    {{-- The operational picture: how much work sits at each stage, and
         nothing else. No money on a list anybody can leave open on a shared
         screen. --}}
    <div class="mb-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach (['outstanding', 'awaiting_address', 'preparing', 'dispatched', 'delivery_failed'] as $key)
            <button type="button" wire:click="$set('filter', '{{ $key }}')"
                    class="rounded-lg border px-4 py-3 text-left transition
                           {{ $filter === $key ? 'border-brand-600 bg-brand-50' : 'border-slate-200 bg-white hover:border-brand-300' }}">
                <span class="block text-2xl font-bold tabular-nums text-slate-900">
                    {{ number_format($counts[$key] ?? 0) }}
                </span>
                <span class="mt-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {{ $tabs[$key] }}
                </span>
            </button>
        @endforeach
    </div>

    @if (($counts['awaiting_address'] ?? 0) > 0)
        <x-alert variant="info" class="mb-6">
            <strong>{{ $counts['awaiting_address'] }}</strong>
            {{ Str::plural('delivery', $counts['awaiting_address']) }}
            {{ $counts['awaiting_address'] === 1 ? 'has' : 'have' }} no address yet. These are
            waiting on the customer, not on the warehouse — most are auction wins, where the order
            was created the moment the auction closed.
        </x-alert>
    @endif

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-56">
            <x-field label="Show" name="filter">
                <select wire:model.live="filter" id="filter"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    @foreach ($tabs as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <div class="w-full sm:w-48">
            <x-field label="Region" name="region">
                <select wire:model.live="region" id="region"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="">Everywhere</option>
                    @foreach ($regions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <div class="w-full sm:w-72">
            <x-field label="Search" name="search">
                <input type="search" wire:model.live.debounce.400ms="search" id="search"
                       placeholder="Order, delivery reference or customer"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
            </x-field>
        </div>
    </div>

    @if ($deliveries->isEmpty())
        <x-empty-state
            title="Nothing here"
            description="No deliveries match this filter." />
    @else
        <x-card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Delivery</th>
                            <th class="px-5 py-3 font-semibold">Order</th>
                            <th class="px-5 py-3 font-semibold">Customer</th>
                            <th class="px-5 py-3 font-semibold">Item</th>
                            <th class="px-5 py-3 font-semibold">Going to</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">Opened</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($deliveries as $delivery)
                            <tr>
                                <td class="px-5 py-3 font-mono text-xs text-slate-600">
                                    {{ $delivery->reference }}
                                    @if ($delivery->attempts > 1)
                                        <span class="mt-1 block text-slate-500">
                                            {{ $delivery->attempts }} attempts
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.orders.show', $delivery->order) }}" wire:navigate
                                       class="font-semibold text-brand-800 underline">
                                        {{ $delivery->order->order_number }}
                                    </a>
                                </td>
                                <td class="px-5 py-3 text-slate-700">{{ $delivery->order->user?->name }}</td>
                                <td class="max-w-xs px-5 py-3 text-slate-600">
                                    {{ $delivery->order->item()?->product_name_snapshot }}
                                </td>
                                <td class="max-w-xs px-5 py-3 text-slate-600">
                                    @if ($delivery->hasAddress())
                                        {{ $delivery->city }}@if ($delivery->region), {{ $delivery->region }}@endif
                                    @else
                                        <span class="text-amber-700">No address yet</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <x-badge :classes="$delivery->status->badgeClasses()">
                                        {{ $delivery->status->label() }}
                                    </x-badge>
                                    @if ($delivery->failure_reason)
                                        <span class="mt-1 block text-xs text-red-700">
                                            {{ $delivery->failure_reason->label() }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-500">
                                    {{ $delivery->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-6">{{ $deliveries->links() }}</div>
    @endif

    <p class="mt-8 text-xs text-slate-500">
        Deliveries are handled by hand. Nothing here talks to a courier, and no reference on this
        screen can be looked up anywhere outside this platform.
    </p>
</div>

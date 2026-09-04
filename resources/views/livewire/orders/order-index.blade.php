{{-- A customer's own orders. Money is money; credits never appear here as
     currency. --}}

<div>
    <x-page-header
        title="My orders"
        description="Everything you have bought, and anything still awaiting payment." />

    @if ($orders->isEmpty())
        <x-empty-state
            title="No orders yet"
            description="Buy a product outright, or win an auction, and it will appear here." />
    @else
        <x-card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Order</th>
                            <th class="px-5 py-3 font-semibold">Item</th>
                            <th class="px-5 py-3 font-semibold">How</th>
                            <th class="px-5 py-3 font-semibold">Total</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($orders as $order)
                            <tr>
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-slate-900">{{ $order->order_number }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $order->placed_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                                    </p>
                                </td>

                                <td class="px-5 py-3 text-slate-700">
                                    {{ $order->item()?->product_name_snapshot ?? '—' }}
                                </td>

                                <td class="px-5 py-3">
                                    <x-badge :classes="$order->source->badgeClasses()">
                                        {{ $order->source->label() }}
                                    </x-badge>
                                </td>

                                <td class="px-5 py-3 font-semibold tabular-nums text-slate-900">
                                    <x-money :amount="$order->total()" />
                                </td>

                                <td class="px-5 py-3">
                                    <x-badge :classes="$order->status->badgeClasses()">
                                        {{ $order->status->label() }}
                                    </x-badge>
                                </td>

                                <td class="px-5 py-3 text-right">
                                    @if ($order->isPayable())
                                        <x-button size="sm" href="{{ route('checkout.show', $order) }}"
                                                  wire:navigate>Pay now</x-button>
                                    @else
                                        <x-button variant="ghost" size="sm"
                                                  href="{{ route('orders.show', $order) }}"
                                                  wire:navigate>View</x-button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-5 py-4">{{ $orders->links() }}</div>
        </x-card>
    @endif
</div>

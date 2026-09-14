{{-- What was asked of the payment provider, and what came back.

     THERE IS NO "MARK PAID" CONTROL HERE, and there is no code path for one.
     An order becomes paid only through a payment verified server-to-server
     with the provider; a button here would have nothing to call. If a payment
     is stuck, the answer is to re-run verification from the order, never to
     assert an outcome from a browser. --}}

<x-admin.shell>

    <x-page-header
        title="Payments"
        description="Every attempt against the payment provider, and the answer it gave." />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-56">
            <x-field label="Status" name="status">
                <select wire:model.live="status" id="status"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="">Every status</option>
                    @foreach ($statuses as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <div class="w-full sm:w-72">
            <x-field label="Reference or order number" name="search">
                <input type="search" id="search" wire:model.live.debounce.400ms="search" maxlength="64"
                       autocomplete="off"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
            </x-field>
        </div>
    </div>

    <x-card :padded="false">
        @if ($payments->isEmpty())
            <div class="p-5">
                <x-empty-state
                    title="No payment attempt"
                    description="Nothing matches this filter. Several attempts on one order is normal — at most one of them ever succeeds." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Reference</th>
                            <th class="px-5 py-3 font-semibold">Order</th>
                            <th class="px-5 py-3 font-semibold">Customer</th>
                            <th class="px-5 py-3 font-semibold">Asked for</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">When</th>
                            <th class="px-5 py-3"><span class="sr-only">Open the order</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($payments as $payment)
                            <tr>
                                {{-- The provider's reference for the transaction.
                                     Not a credential, and the only provider
                                     identifier this platform stores. --}}
                                <td class="px-5 py-3 font-mono text-xs text-slate-700">
                                    {{ $payment->provider_reference }}
                                    @if ($payment->provider_channel)
                                        <p class="mt-1 font-sans text-xs text-slate-500">{{ $payment->provider_channel }}</p>
                                    @endif
                                </td>

                                <td class="px-5 py-3 font-semibold text-slate-900">
                                    {{ $payment->order?->order_number ?? '—' }}
                                </td>

                                <td class="px-5 py-3 text-slate-700">
                                    {{ $payment->order?->user?->name ?? 'Removed account' }}
                                </td>

                                {{-- What the attempt was opened with, which is
                                     what verification compares against. The
                                     order's own total can move; this cannot. --}}
                                <td class="px-5 py-3 tabular-nums text-slate-900">
                                    <x-money :amount="$payment->amount()" />
                                    @if ($payment->refunds->isNotEmpty())
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $payment->refunds->count() }}
                                            {{ Str::plural('refund', $payment->refunds->count()) }} against it
                                        </p>
                                    @endif
                                </td>

                                <td class="px-5 py-3">
                                    <x-badge :classes="$payment->status->badgeClasses()">
                                        {{ $payment->status->label() }}
                                    </x-badge>
                                </td>

                                <td class="px-5 py-3 text-slate-500">
                                    {{ ($payment->paid_at ?? $payment->created_at)?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                </td>

                                <td class="px-5 py-3 text-right">
                                    @if ($payment->order && auth()->user()->can('orders.view'))
                                        <x-button size="sm" variant="ghost"
                                                  href="{{ route('admin.orders.show', $payment->order) }}" wire:navigate>
                                            Open order
                                        </x-button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-5 py-4">{{ $payments->links() }}</div>
        @endif
    </x-card>

    <p class="mt-6 text-xs text-slate-500">
        This screen reads. An order becomes paid only when a payment is verified with the provider
        server-to-server, and there is no control here or anywhere else that asserts money arrived.
    </p>
</x-admin.shell>

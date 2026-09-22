<div>
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            title="Credit purchases"
            description="Every credit package you have bought, and whether the credits arrived." />

        <x-button href="{{ route('credits.packages') }}" wire:navigate variant="primary" class="shrink-0">
            Buy credits
        </x-button>
    </div>

    <x-card :padded="false">
        @if ($purchases->isEmpty())
            <div class="p-6">
                <x-empty-state
                    title="You have not bought any credits yet"
                    description="Credit packages you buy will be listed here with their payment status.">
                    <x-button href="{{ route('credits.packages') }}" wire:navigate variant="primary" size="sm">
                        Browse packages
                    </x-button>
                </x-empty-state>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Package</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Credits</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Paid</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Reference</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($purchases as $purchase)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                    {{ $purchase->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-900">
                                    {{ $purchase->package_name_snapshot }}
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                    <x-credits :amount="$purchase->credit_amount" bare />
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                    {{ settings()->getString('currency_symbol', 'GH₵') }} {{ $purchase->amount()->format() }}
                                </td>
                                <td class="px-4 py-3">
                                    <x-badge :classes="$purchase->status->badgeClasses()">
                                        {{ $purchase->status->label() }}
                                    </x-badge>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                    {{ $purchase->provider_reference }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($purchases->hasPages())
                <div class="border-t border-slate-100 p-4">{{ $purchases->links() }}</div>
            @endif
        @endif
    </x-card>
</div>

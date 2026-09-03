<div>
    <x-admin.nav />

    <x-page-header
        title="Credit purchases"
        description="Every credit package purchase and whether its credits were posted. Read-only — administrative credit changes go through wallet adjustments." />

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        <x-input type="search" wire:model.live.debounce.300ms="search"
                 placeholder="Search by reference, phone, email or name" class="max-w-md" />

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
            <table class="w-full min-w-[60rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Customer</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Package</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Credits</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Reference</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Ledger</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($purchases as $purchase)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                {{ $purchase->created_at->format('j M Y, H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                @can('wallets.inspect')
                                    <a href="{{ route('admin.wallets.show', $purchase->user) }}" wire:navigate
                                       class="font-medium text-brand-700 hover:text-brand-800">
                                        {{ $purchase->user?->name ?? 'No name given' }}
                                    </a>
                                @else
                                    <span class="font-medium text-slate-900">
                                        {{ $purchase->user?->name ?? 'No name given' }}
                                    </span>
                                @endcan
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $purchase->package_name_snapshot }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                {{ number_format($purchase->credit_amount) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ $purchase->currency }} {{ $purchase->amount()->format() }}
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :classes="$purchase->status->badgeClasses()">
                                    {{ $purchase->status->label() }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">
                                {{ $purchase->provider_reference }}
                                @if ($purchase->provider_channel)
                                    <span class="block text-slate-400">{{ $purchase->provider_channel }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                @php($ledgerId = $purchase->creditTransactions()->value('id'))
                                @if ($ledgerId)
                                    <span class="font-mono">#{{ $ledgerId }}</span>
                                @else
                                    <span class="text-slate-400">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10">
                                <x-empty-state
                                    title="No purchases match"
                                    description="Credit purchases will appear here as customers make them." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($purchases->hasPages())
        <div class="mt-4">{{ $purchases->links() }}</div>
    @endif
</div>

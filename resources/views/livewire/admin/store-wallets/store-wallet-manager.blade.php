<div>
    <x-admin.nav />

    <x-page-header
        title="Store Wallets"
        description="Non-withdrawable purchasing power returned to losing bidders. Balances are derived from the ledger and cannot be edited directly." />

    <div class="mb-4">
        <x-input type="search" wire:model.live.debounce.300ms="search"
                 aria-label="Search by phone, email or name"
                 placeholder="Search by phone, email or name" class="max-w-md" />
    </div>

    @php
        $unhealthy = $reports->reject(fn (\App\Domain\StoreWallet\Services\StoreWalletReport $r): bool => $r->isHealthy());
    @endphp

    @if ($unhealthy->isNotEmpty())
        <x-alert variant="warning" class="mb-6">
            <p class="font-semibold">
                {{ count($unhealthy) }} {{ Str::plural('wallet', count($unhealthy)) }} on this page with ledger discrepancies.
            </p>
            <p class="mt-1 text-sm">
                The Store Wallet ledger disagrees with the materialized balance for the wallets below. These are
                report-only; no automatic repair has been attempted.
            </p>
        </x-alert>
    @endif

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">User</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Phone</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Transactions</th>
                        <th scope="col" class="px-4 py-3 text-center font-semibold">Ledger</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($wallets as $wallet)
                        @php
                            $report = $reports->get($wallet->id);
                            $healthy = $report?->isHealthy() ?? true;
                        @endphp
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $wallet->user?->name ?? 'No name given' }}</span>
                                @if ($wallet->user?->email)
                                    <span class="block text-xs text-slate-500">{{ $wallet->user->email }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums text-slate-600">
                                {{ $wallet->user ? app(\App\Domain\Shared\Phone\PhoneNumberNormalizer::class)->forDisplay($wallet->user->phone) : '-' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                <x-money :amount="$wallet->balance()" />
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ number_format($wallet->transactions()->count()) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if ($healthy)
                                    <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-200">Healthy</x-badge>
                                @else
                                    <x-badge classes="bg-red-50 text-red-800 ring-red-200">
                                        {{ $report->problemCount() }} {{ Str::plural('issue', $report->problemCount()) }}
                                    </x-badge>
                                    <ul class="mt-1 list-disc pl-4 text-left text-xs text-red-700">
                                        @foreach ($report->problems as $problem)
                                            <li>{{ $problem }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-button href="{{ route('admin.wallets.show', $wallet->user) }}" wire:navigate
                                          variant="secondary" size="sm">Inspect</x-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10">
                                <x-empty-state
                                    title="No store wallets"
                                    description="Store wallets are created when an auction ends and another party acquires the product." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($wallets->hasPages())
        <div class="mt-4">{{ $wallets->links() }}</div>
    @endif
</div>

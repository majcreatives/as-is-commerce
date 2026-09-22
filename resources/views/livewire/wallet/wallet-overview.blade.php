<div>
    <x-page-header
        title="Wallet"
        description="Your bidding credits and Store Wallet." />

    {{-- Balances. These are real figures read from the ledger, so a new
         account correctly shows zero rather than a placeholder. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2">
        <x-card>
            <p class="text-sm font-medium text-slate-500">Bidding credits</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                <x-credits :amount="$spendableCredits" bare />
            </p>
            <p class="mt-1 text-xs text-slate-500">
                available to bid with
            </p>

            @if ($expiringSoon > 0)
                <p class="mt-3 border-t border-slate-100 pt-3 text-xs font-medium text-accent-800">
                    <x-credits :amount="$expiringSoon" /> expiring within a month
                </p>
            @endif
        </x-card>

        <x-card>
            <p class="text-sm font-medium text-slate-500">Store Wallet</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                {{ settings()->getString('currency_symbol', 'GH₵') }} {{ $storeWallet->balance()->format() }}
            </p>
            <p class="mt-1 text-xs text-slate-500">
                Purchasing power for eligible purchases — not cash, not a credit refund.
            </p>
        </x-card>
    </div>

    {{-- Tabs --}}
    <div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 pb-3" role="tablist">
        @foreach ([
            'credits' => 'Credit history',
            'store_wallet' => 'Store Wallet history',
            'lots' => 'Credit batches',
        ] as $key => $label)
            <button type="button"
                    role="tab"
                    wire:click="$set('tab', '{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'rounded-md px-3 py-2 text-sm font-medium transition',
                        'bg-brand-50 text-brand-800' => $tab === $key,
                        'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => $tab !== $key,
                    ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'credits')
        <x-card :padded="false">
            @if ($creditTransactions->isEmpty())
                <div class="p-6">
                    <x-empty-state
                        title="No credit activity yet"
                        description="Every credit you receive or spend will be listed here, with the date and the reason." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Activity</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Credits</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($creditTransactions as $transaction)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {{ $transaction->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-slate-900">{{ $transaction->type->label() }}</span>
                                        @if ($transaction->description)
                                            <span class="block text-xs text-slate-500">{{ $transaction->description }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $transaction->isCredit() ? 'text-emerald-700' : 'text-slate-900' }}">
                                        {{ $transaction->signedAmount() }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-slate-500">
                                        <x-credits :amount="$transaction->balance_after" bare />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($creditTransactions->hasPages())
                    <div class="border-t border-slate-100 p-4">{{ $creditTransactions->links() }}</div>
                @endif
            @endif
        </x-card>
    @endif

    @if ($tab === 'store_wallet')
        <x-card :padded="false">
            @if ($storeWalletTransactions->isEmpty())
                <div class="p-6">
                    <x-empty-state
                        title="No Store Wallet activity yet"
                        description="When you earn Store Wallet value from bids, it will appear here with the date and the reason." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Activity</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($storeWalletTransactions as $transaction)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {{ $transaction->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-slate-900">{{ $transaction->type->label() }}</span>
                                        @if ($transaction->description)
                                            <span class="block text-xs text-slate-500">{{ $transaction->description }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $transaction->isCredit() ? 'text-emerald-700' : 'text-slate-900' }}">
                                        {{ ($transaction->isCredit() ? '+' : '-') . $transaction->absoluteAmount()->format() }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-slate-500">
                                        {{ $transaction->balanceAfter()->format() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($storeWalletTransactions->hasPages())
                    <div class="border-t border-slate-100 p-4">{{ $storeWalletTransactions->links() }}</div>
                @endif
            @endif
        </x-card>
    @endif

    @if ($tab === 'lots')
        <x-card :padded="false">
            @if ($lots->isEmpty())
                <div class="p-6">
                    <x-empty-state
                        title="No credit batches"
                        description="Credits arrive in batches. Each batch keeps its own source and expiry, and batches that expire soonest are spent first." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-semibold">Source</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Received</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Expires</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Remaining</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($lots as $lot)
                                <tr>
                                    <td class="px-4 py-3">
                                        <x-badge :classes="$lot->source_type->badgeClasses()">
                                            {{ $lot->source_type->label() }}
                                        </x-badge>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500">
                                        {{ $lot->created_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y') }}
                                    </td>
                                    <td class="px-4 py-3 text-slate-500">
                                        {{ $lot->expires_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y') ?? 'Does not expire' }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums">
                                        <span class="font-semibold text-slate-900"><x-credits :amount="$lot->remaining_amount" bare /></span>
                                        <span class="text-xs text-slate-400">of <x-credits :amount="$lot->original_amount" bare /></span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    @endif
</div>

<x-admin.shell>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            :title="$user->name ?? 'Wallet'"
            :description="app(\App\Domain\Shared\Phone\PhoneNumberNormalizer::class)->forDisplay($user->phone) . ($user->email ? ' · ' . $user->email : '')" />

        <x-button href="{{ route('admin.wallets.index') }}" wire:navigate variant="secondary" class="shrink-0">
            Back to wallets
        </x-button>
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    {{-- Balances --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-card>
            <p class="text-sm font-medium text-slate-500">Ledger balance</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                <x-credits :amount="$creditWallet->balance" bare />
            </p>
            <p class="mt-1 text-xs text-slate-500">credits recorded in the ledger</p>
        </x-card>

        <x-card>
            <p class="text-sm font-medium text-slate-500">Spendable now</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                <x-credits :amount="$spendableCredits" bare />
            </p>
            @if ($expiredUnclaimed > 0)
                <p class="mt-1 text-xs font-medium text-accent-800">
                    <x-credits :amount="$expiredUnclaimed" bare /> expired, not yet written off
                </p>
            @else
                <p class="mt-1 text-xs text-slate-500">excludes expired batches</p>
            @endif
        </x-card>

        <x-card>
            <p class="text-sm font-medium text-slate-500">Cash balance</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                {{ $cashWallet->balance()->format() }}
            </p>
            <p class="mt-1 text-xs text-slate-500">{{ $cashWallet->currency }}</p>
        </x-card>
    </div>

    {{-- Reconciliation --}}
    @can('wallets.reconcile')
        <x-card title="Reconciliation" subtitle="Check that the balance, the ledger and the credit batches agree."
                class="mb-6">
            @if ($report === null)
                <p class="text-sm text-slate-600">
                    Reconciliation recomputes the balance from the ledger and from the remaining credit batches,
                    and reports any disagreement. It reports only — discrepancies are never repaired
                    automatically, because a silent fix would destroy the evidence of what caused it.
                </p>
                <div class="mt-4">
                    <x-button wire:click="reconcile" variant="secondary" size="sm" wire:loading.attr="disabled">
                        Run reconciliation
                    </x-button>
                </div>
            @else
                @if ($report['healthy'])
                    <x-alert variant="success">
                        Consistent. Ledger sum, stored balance and remaining batch credits all agree at
                        <x-credits :amount="$report['stored_balance']" bare />.
                    </x-alert>
                @else
                    <x-alert variant="danger">
                        <p class="font-semibold">
                            {{ count($report['problems']) }}
                            {{ Str::plural('discrepancy', count($report['problems'])) }} found. Not repaired.
                        </p>
                        <ul class="mt-2 list-inside list-disc space-y-1">
                            @foreach ($report['problems'] as $problem)
                                <li>{{ $problem }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                @endif

                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-slate-500">Stored balance</dt>
                        <dd class="font-semibold tabular-nums"><x-credits :amount="$report['stored_balance']" bare /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Ledger sum</dt>
                        <dd class="font-semibold tabular-nums"><x-credits :amount="$report['ledger_balance']" bare /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Batches remaining</dt>
                        <dd class="font-semibold tabular-nums"><x-credits :amount="$report['lots_remaining']" bare /></dd>
                    </div>
                </dl>

                <div class="mt-4">
                    <x-button wire:click="reconcile" variant="secondary" size="sm">Run again</x-button>
                </div>
            @endif
        </x-card>
    @endcan

    {{-- Adjustment --}}
    @can('credits.adjust')
        <x-card title="Adjust credits"
                subtitle="Posts a ledger entry. There is no way to set a balance directly."
                class="mb-6">
            @unless ($showAdjustment)
                <x-button wire:click="$set('showAdjustment', true)" variant="secondary" size="sm">
                    Make an adjustment
                </x-button>
            @else
                <form wire:submit="adjust" class="space-y-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-field label="Credits" name="adjustmentAmount"
                                 :error="$errors->first('adjustmentAmount')"
                                 hint="Positive to grant credits, negative to take them back.">
                            <x-input id="adjustmentAmount" type="number" wire:model="adjustmentAmount"
                                     :error="$errors->has('adjustmentAmount')" required />
                        </x-field>

                        <x-field label="Source" name="adjustmentSource" :error="$errors->first('adjustmentSource')"
                                 hint="Determines expiry and refund treatment of the batch created.">
                            <select id="adjustmentSource" wire:model="adjustmentSource"
                                    class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                @foreach ($sources as $source)
                                    <option value="{{ $source->value }}">{{ $source->label() }}</option>
                                @endforeach
                            </select>
                        </x-field>

                        <div class="sm:col-span-2">
                            <x-field label="Reason" name="adjustmentReason" :error="$errors->first('adjustmentReason')"
                                     hint="Recorded on the ledger entry and in the audit log. Write it for someone reviewing this in a year.">
                                <x-input id="adjustmentReason" wire:model="adjustmentReason"
                                         :error="$errors->has('adjustmentReason')" required />
                            </x-field>
                        </div>

                        <x-field label="Expires at" name="adjustmentExpiresAt"
                                 :error="$errors->first('adjustmentExpiresAt')" optional
                                 hint="Only applies when granting credits. Leave blank for credits that do not expire.">
                            <x-input id="adjustmentExpiresAt" type="datetime-local"
                                     wire:model="adjustmentExpiresAt"
                                     :error="$errors->has('adjustmentExpiresAt')" />
                        </x-field>
                    </div>

                    <div class="flex justify-end gap-2">
                        <x-button type="button" wire:click="$set('showAdjustment', false)" variant="secondary">
                            Cancel
                        </x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="disabled">
                            Post adjustment
                        </x-button>
                    </div>
                </form>
            @endunless
        </x-card>
    @endcan

    {{-- Credit batches --}}
    <x-card title="Credit batches" subtitle="Where this user's credits came from, and what is left of each."
            :padded="false" class="mb-6">
        @if ($lots->isEmpty())
            <div class="p-6">
                <x-empty-state title="No credit batches" description="This user has never received credits." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Batch</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Source</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Expires</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Original</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Remaining</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($lots as $lot)
                            <tr @class(['bg-slate-50/60' => $lot->isExhausted()])>
                                <td class="px-4 py-3 tabular-nums text-slate-500">#{{ $lot->id }}</td>
                                <td class="px-4 py-3">
                                    <x-badge :classes="$lot->source_type->badgeClasses()">
                                        {{ $lot->source_type->label() }}
                                    </x-badge>
                                </td>
                                <td class="px-4 py-3 text-slate-500">
                                    @if ($lot->expires_at)
                                        {{ $lot->expires_at->format('j M Y') }}
                                        @if ($lot->isExpired())
                                            <span class="text-xs font-medium text-accent-800">(expired)</span>
                                        @endif
                                    @else
                                        Does not expire
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-500">
                                    <x-credits :amount="$lot->original_amount" bare />
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                    <x-credits :amount="$lot->remaining_amount" bare />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    {{-- Credit ledger --}}
    <x-card title="Credit ledger" subtitle="Append-only. Entries are never edited or deleted."
            :padded="false" class="mb-6">
        @if ($creditTransactions->isEmpty())
            <div class="p-6">
                <x-empty-state title="No credit transactions" description="Nothing has moved in this wallet yet." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">#</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Type</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Reason</th>
                            <th scope="col" class="px-4 py-3 font-semibold">By</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($creditTransactions as $transaction)
                            <tr>
                                <td class="px-4 py-3 tabular-nums text-slate-400">{{ $transaction->id }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                    {{ $transaction->created_at->format('j M Y, H:i') }}
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-900">{{ $transaction->type->label() }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ $transaction->description ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-500">
                                    {{ $transaction->creator?->name ?? ($transaction->created_by ? 'Staff #'.$transaction->created_by : 'System') }}
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

    {{-- Cash ledger --}}
    @can('cash.view')
        <x-card title="Cash ledger" subtitle="Real money, accounted for separately from credits."
                :padded="false">
            @if ($cashTransactions->isEmpty())
                <div class="p-6">
                    <x-empty-state title="No cash transactions" description="No money has moved through this account." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-semibold">#</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Type</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($cashTransactions as $transaction)
                                <tr>
                                    <td class="px-4 py-3 tabular-nums text-slate-400">{{ $transaction->id }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {{ $transaction->created_at->format('j M Y, H:i') }}
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-900">{{ $transaction->type->label() }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">
                                        {{ $transaction->signedAmount() }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-slate-500">
                                        {{ $transaction->balanceAfter()->format() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($cashTransactions->hasPages())
                    <div class="border-t border-slate-100 p-4">{{ $cashTransactions->links() }}</div>
                @endif
            @endif
        </x-card>
    @endcan
</x-admin.shell>

{{-- Referrals, for staff.

     There is no control here to grant credits, set a reward amount, mark a
     purchase as qualifying, or reassign a referrer — each would be a way to
     create credits with no purchase behind them.

     Invalidating is offered only before a reward is issued. After that the
     credits are in the ledger, possibly already spent, and a status change
     would not take them back. --}}

<x-admin.shell>

    <x-page-header
        title="Referrals"
        description="Who introduced whom, and what it earned." />

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @php
            $cards = [
                'total' => 'Total',
                'attributed' => 'Signed up',
                'qualified' => 'Qualified',
                'rewarded' => 'Rewarded',
            ];
        @endphp

        @foreach ($cards as $key => $label)
            <x-card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                    {{ number_format($summary[$key] ?? 0) }}
                </p>
            </x-card>
        @endforeach

        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Credits issued</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                {{ number_format($summary['credits_issued'] ?? 0) }}
            </p>
            {{-- Counted from the reward snapshots, never from wallet balances:
                 a balance says nothing about how many people were introduced. --}}
            <p class="mt-1 text-xs text-slate-500">From referral records.</p>
        </x-card>
    </div>

    @if (count($anomalies) > 0)
        <x-alert variant="danger" class="mb-6">
            <p class="font-semibold">
                {{ count($anomalies) }} referral
                {{ Str::plural('anomaly', count($anomalies)) }}.
            </p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($anomalies as $anomaly)
                    <li>
                        <span class="font-mono text-xs">{{ $anomaly['type'] }}</span>
                        @if ($anomaly['referral_id'])
                            (#{{ $anomaly['referral_id'] }})
                        @endif
                        — {{ $anomaly['detail'] }}
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-sm">
                Nothing has been changed, and nothing here issues credits. A person decides.
            </p>
        </x-alert>
    @endif

    @error('invalidation')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="w-full sm:w-56">
            <x-field label="Status" name="filter">
                <select wire:model.live="filter" id="filter"
                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    <option value="all">All referrals</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <div class="w-full sm:w-72">
            <x-field label="Search" name="search">
                <input type="search" wire:model.live.debounce.400ms="search" id="search"
                       placeholder="Code or customer name" maxlength="80"
                       class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
            </x-field>
        </div>
    </div>

    @if ($referrals->isEmpty())
        <x-empty-state
            title="No referrals"
            description="Nothing matches this filter." />
    @else
        <x-card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Referrer</th>
                            <th class="px-5 py-3 font-semibold">Referred</th>
                            <th class="px-5 py-3 font-semibold">Code</th>
                            <th class="px-5 py-3 font-semibold">Status</th>
                            <th class="px-5 py-3 font-semibold">Qualifying order</th>
                            <th class="px-5 py-3 font-semibold">Reward</th>
                            <th class="px-5 py-3 font-semibold">Joined</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($referrals as $referral)
                            <tr>
                                <td class="px-5 py-3 text-slate-700">{{ $referral->referrer?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-700">{{ $referral->referred?->name ?? '—' }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ $referral->code_used }}</td>
                                <td class="px-5 py-3">
                                    <x-badge :classes="$referral->status->badgeClasses()">
                                        {{ $referral->status->label() }}
                                    </x-badge>
                                    @if ($referral->invalidation_reason)
                                        <span class="mt-1 block max-w-xs text-xs text-red-700">
                                            {{ $referral->invalidation_reason }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    @if ($referral->qualifyingOrder)
                                        <a href="{{ route('admin.orders.show', $referral->qualifyingOrder) }}"
                                           wire:navigate class="font-semibold text-brand-800 underline">
                                            {{ $referral->qualifyingOrder->order_number }}
                                        </a>
                                    @else
                                        <span class="text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-900">
                                    @if ($referral->reward_credits)
                                        {{-- Credits, as a count. Never a cedis
                                             figure: a referral reward is not a
                                             payout. --}}
                                        <x-credits :amount="$referral->reward_credits" />
                                        @if ($referral->credit_transaction_id)
                                            <span class="mt-1 block font-mono text-xs text-slate-500">
                                                ledger #{{ $referral->credit_transaction_id }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-slate-500">
                                    {{ $referral->attributed_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @can('referrals.manage')
                                        @if ($referral->isOutstanding())
                                            <x-button size="sm" variant="ghost"
                                                      wire:click="startInvalidating({{ $referral->id }})">
                                                Not eligible
                                            </x-button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>

                            @if ($invalidating === $referral->id)
                                <tr class="bg-amber-50/50">
                                    <td colspan="8" class="px-5 py-4">
                                        <p class="text-sm font-semibold text-slate-900">
                                            Mark this referral as not eligible
                                        </p>
                                        <p class="mt-1 text-sm text-slate-600">
                                            The relationship stays on record with your name and this
                                            reason attached. No credits are issued, and none are
                                            taken back.
                                        </p>

                                        <div class="mt-3 flex flex-wrap items-end gap-3">
                                            <div class="w-full sm:w-96">
                                                <x-field label="Reason" name="invalidationReason">
                                                    <input type="text" wire:model="invalidationReason"
                                                           id="invalidationReason" maxlength="500"
                                                           class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                                </x-field>
                                            </div>

                                            <x-button size="sm" wire:click="invalidate">Confirm</x-button>
                                            <x-button size="sm" variant="ghost" wire:click="cancelInvalidating">
                                                Cancel
                                            </x-button>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-6">{{ $referrals->links() }}</div>
    @endif

    <p class="mt-8 text-xs text-slate-500">
        Referral rewards are ordinary platform credits, issued through the credit ledger. They are
        never cash, are never paid out, and cannot be transferred between customers. Nothing on this
        screen can grant them.
    </p>
</x-admin.shell>

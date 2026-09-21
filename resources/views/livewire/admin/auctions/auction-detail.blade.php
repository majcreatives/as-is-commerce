{{-- One auction, in full.

     There is no form for editing rules, a settlement amount or a winner. Those
     are frozen or derived, and offering a control that always failed would be
     worse than not offering one. --}}

<x-admin.shell>

    <div class="mb-6 flex flex-wrap items-center gap-2">
        <x-badge :classes="$auction->status->badgeClasses()">{{ $auction->status->label() }}</x-badge>

        @if ($auction->closure_reason)
            <x-badge :classes="$auction->closure_reason->badgeClasses()">
                {{ $auction->closure_reason->label() }}
            </x-badge>
        @endif
    </div>

    <x-page-header
        :title="'Auction #'.$auction->id"
        :description="$auction->product->name" />

    @error('lifecycle')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- ------------------------------------------------ The figures --}}
            <x-card title="The three figures"
                    subtitle="Each is independent. None is calculated from another.">
                <dl class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $auction->rules()->bidModel->leaderLabel() }}
                        </dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                            {{ $highestBid ? number_format($highestBid->rankingValue()) : '—' }}
                        </dd>
                        <dd class="text-xs text-slate-500">a count of credits, not money</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Auction Settlement Amount
                        </dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                            <x-money :amount="$auction->settlementAmount()" />
                        </dd>
                        <dd class="text-xs text-slate-500">what a normal winner pays</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Buy Now price
                        </dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                            <x-money :amount="$auction->product->buyNowPrice()" />
                        </dd>
                        <dd class="text-xs text-slate-500">the product's own price</dd>
                    </div>
                </dl>

                <p class="mt-4 border-t border-slate-100 pt-3 text-sm text-slate-600">
                    Winner also pays applicable delivery and tax. On this auction's frozen rules that
                    totals <strong><x-money :amount="$auction->settlementTotal()" /></strong>.
                </p>
            </x-card>

            {{-- ------------------------------------------------- The outcome --}}
            <x-card title="Outcome">
                @if ($auction->endedByBuyNow())
                    <p class="text-sm text-slate-700">
                        <strong>Ended by a completed Buy Now purchase.</strong>
                        Bought by {{ $auction->buyNowBuyer?->name ?? 'a customer' }} on
                        {{ $auction->buy_now_ended_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}.
                    </p>

                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Eligible consumed bid credits</dt>
                            <dd class="tabular-nums font-semibold text-slate-900">
                                {{ number_format($auction->buy_now_eligible_credits ?? 0) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Credit discount applied</dt>
                            <dd class="tabular-nums font-semibold text-slate-900">
                                <x-money :amount="App\Domain\Shared\Money\Money::fromMinor($auction->buy_now_discount_minor ?? 0, $auction->currency)" />
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 border-t border-slate-100 pt-2">
                            <dt class="font-semibold text-slate-900">Paid</dt>
                            <dd class="tabular-nums font-bold text-slate-900">
                                <x-money :amount="App\Domain\Shared\Money\Money::fromMinor($auction->buy_now_payable_minor ?? 0, $auction->currency)" />
                            </dd>
                        </div>
                    </dl>

                    <x-alert variant="info" class="mt-4">
                        The bidder who was leading did <strong>not</strong> win. Credits already
                        committed to this auction stay consumed.
                    </x-alert>
                @elseif ($auction->hasBidWinner())
                    <p class="text-sm text-slate-700">
                        <strong>{{ $auction->rules()->bidModel->winnerSentence($auction->winningBid?->rankingValue() ?? 0) }}</strong>
                        {{ $auction->winner?->name }} owes
                        <x-money :amount="$auction->settlementAmount()" /> plus applicable charges.
                    </p>

                    <p class="mt-2 text-sm text-slate-500">
                        Settlement due
                        {{ $auction->settlement_due_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') ?? '—' }}.
                        Settlement checkout arrives in a later stage; nothing here takes payment.
                    </p>
                @elseif ($auction->status->isTerminal() || $auction->closure_reason)
                    <p class="text-sm text-slate-700">
                        {{ $auction->closure_reason?->label() ?? $auction->status->label() }}. No winner
                        was recorded.
                    </p>
                @else
                    <p class="text-sm text-slate-500">
                        Still running. The winner is resolved from the bid records when it closes, and
                        is not decided until then.
                    </p>
                @endif
            </x-card>

            {{-- ------------------------------------------------- Settlement --}}
            @if ($settlementOrder || $buyNowOrders->isNotEmpty())
                <x-card title="Orders from this auction"
                        subtitle="What was owed, and what actually acquired the product."
                        :padded="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Order</th>
                                    <th class="px-5 py-3 font-semibold">Customer</th>
                                    <th class="px-5 py-3 font-semibold">Kind</th>
                                    <th class="px-5 py-3 font-semibold">Total</th>
                                    <th class="px-5 py-3 font-semibold">State</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach (collect([$settlementOrder])->filter()->concat($buyNowOrders) as $order)
                                    <tr class="{{ $order->isFulfilmentBlocked() ? 'bg-amber-50/60' : '' }}">
                                        <td class="px-5 py-3">
                                            <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                               class="font-semibold text-brand-800 underline">
                                                {{ $order->order_number }}
                                            </a>
                                        </td>
                                        <td class="px-5 py-3 text-slate-700">{{ $order->user?->name }}</td>
                                        <td class="px-5 py-3">
                                            <x-badge :classes="$order->source->badgeClasses()">
                                                {{ $order->source->label() }}
                                            </x-badge>
                                        </td>
                                        <td class="px-5 py-3 tabular-nums text-slate-900">
                                            <x-money :amount="$order->total()" />
                                        </td>
                                        <td class="px-5 py-3">
                                            <x-badge :classes="$order->status->badgeClasses()">
                                                {{ $order->status->label() }}
                                            </x-badge>

                                            @if ($order->isFulfilmentBlocked())
                                                <p class="mt-1 text-xs font-semibold text-amber-800">
                                                    fulfilment blocked
                                                </p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($buyNowOrders->where('fulfilment_blocked_reason', '!=', null)->isNotEmpty())
                        <div class="border-t border-slate-100 px-5 py-4">
                            <x-alert variant="warning">
                                One or more payments succeeded against this auction but could not be
                                fulfilled — another transaction acquired the unit first. Those orders
                                stay Paid and are in the attention queue. No refund is issued
                                automatically.
                            </x-alert>
                        </div>
                    @endif
                </x-card>
            @elseif ($auction->hasBidWinner())
                <x-card title="Settlement">
                    <x-alert variant="warning">
                        This auction has a winner but no settlement checkout was opened. The winner
                        cannot pay until one exists.
                    </x-alert>
                </x-card>
            @endif

            {{-- --------------------------------------------- Frozen snapshot --}}
            <x-card title="Frozen rules snapshot"
                    subtitle="Taken when the auction was created. Nothing can change it now.">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    @php($rules = $auction->rules())

                    {{-- Which rule governed this auction, read from its frozen
                         snapshot -- so anybody reading an old auction can see
                         whether it was decided by a single bid or a total. --}}
                    <div class="flex justify-between gap-4 sm:col-span-2">
                        <dt class="text-slate-600">Bidding model</dt>
                        <dd class="font-semibold text-slate-900">{{ $rules->bidModel->label() }}</dd>
                    </div>

                    <div class="flex justify-between gap-4 sm:col-span-2">
                        <dt class="text-slate-600">Winner rule</dt>
                        <dd class="font-semibold text-slate-900">{{ $auction->winnerRule() }}</dd>
                    </div>

                    @if ($rules->bidModel->isCumulative())
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Opening bid</dt>
                            <dd class="tabular-nums text-slate-900">{{ number_format((int) $rules->minimumBidCredits) }} credits</dd>
                        </div>

                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Bid increment</dt>
                            <dd class="tabular-nums text-slate-900">{{ number_format((int) $rules->bidIncrementCredits) }} credits</dd>
                        </div>
                    @else
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Minimum bid</dt>
                            <dd class="tabular-nums text-slate-900">
                                {{ $rules->minimumBidCredits === null
                                    ? 'No minimum' : number_format($rules->minimumBidCredits).' credits' }}
                            </dd>
                        </div>

                        {{-- The earlier rule's field, named for what it is: a lower
                             bound over the leader, not the exact step the newer
                             model uses under the name "Bid increment". --}}
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Minimum increment (earlier rule)</dt>
                            <dd class="tabular-nums text-slate-900">
                                {{ $rules->minimumBidIncrementCredits === null
                                    ? 'No increment' : number_format($rules->minimumBidIncrementCredits).' credits' }}
                            </dd>
                        </div>

                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Raising your own bid</dt>
                            <dd class="text-slate-900">
                                {{ $rules->allowBidIncrease === null
                                    ? 'Not decided' : ($rules->allowBidIncrease ? 'Allowed' : 'Not allowed') }}
                            </dd>
                        </div>
                    @endif

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Bid interval</dt>
                        <dd class="tabular-nums text-slate-900">{{ $rules->minimumBidIntervalMs }} ms</dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Base duration</dt>
                        <dd class="tabular-nums text-slate-900">{{ $rules->baseDurationSeconds }} s</dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Extensions</dt>
                        <dd class="text-slate-900">
                            {{ $rules->extensionsEnabled()
                                ? $rules->maxExtensions.' × '.$rules->extensionSeconds.'s (max '.$rules->maxExtensionTotalSeconds.'s)'
                                : 'Off' }}
                        </dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Buy Now</dt>
                        <dd class="text-slate-900">{{ $rules->buyNowEnabled ? 'Available' : 'Not available' }}</dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Buy Now credit discount</dt>
                        <dd class="text-slate-900">
                            {{-- The discount is the actual cash value of this bidder's consumed
                                 credits -- each credit valued at what its own lot was bought for.
                                 There is no fixed per-credit rate to show here. --}}
                            {{ $rules->buyNowCreditDiscountEnabled ? 'At the credits\' actual cost' : 'Not applied' }}
                        </dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Checkout deadline</dt>
                        <dd class="tabular-nums text-slate-900">{{ $rules->checkoutDeadlineMinutes }} min</dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Forfeit policy</dt>
                        <dd class="text-slate-900">{{ $rules->forfeitPolicy->value }}</dd>
                    </div>

                    <div class="flex justify-between gap-4 sm:col-span-2">
                        <dt class="text-slate-600">Taken from</dt>
                        <dd class="text-slate-900">
                            {{ $rules->rulesetName ?? '—' }} v{{ $rules->rulesetVersion ?? '?' }}
                            (snapshot v{{ $auction->snapshot_version }})
                        </dd>
                    </div>
                </dl>
            </x-card>

            {{-- ------------------------------------------------- Bid history --}}
            @can('bids.inspect')
                <x-card title="Bid history"
                        subtitle="Append-only. Every bid consumed exactly its own credits."
                        :padded="false">
                    @if ($history->isEmpty())
                        <div class="p-5">
                            <x-empty-state title="No bids" description="Nothing has been committed to this auction." />
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-5 py-3 font-semibold">#</th>
                                        <th class="px-5 py-3 font-semibold">Bidder</th>
                                        @if ($auction->rules()->bidModel->isCumulative())
                                            <th class="px-5 py-3 font-semibold">Credits added</th>
                                            <th class="px-5 py-3 font-semibold">Total after</th>
                                        @else
                                            <th class="px-5 py-3 font-semibold">Credits</th>
                                        @endif
                                        <th class="px-5 py-3 font-semibold">Ledger</th>
                                        <th class="px-5 py-3 font-semibold">Placed</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($history as $bid)
                                        <tr class="{{ $highestBid && $bid->id === $highestBid->id ? 'bg-emerald-50/60' : '' }}">
                                            <td class="px-5 py-3 tabular-nums text-slate-500">{{ $bid->sequence }}</td>
                                            <td class="px-5 py-3 text-slate-700">{{ $bid->user?->name }}</td>
                                            @if ($auction->rules()->bidModel->isCumulative())
                                                <td class="px-5 py-3 tabular-nums text-slate-700">
                                                    +{{ number_format($bid->amount_credits) }}
                                                </td>
                                                <td class="px-5 py-3 font-semibold tabular-nums text-slate-900">
                                                    {{ number_format($bid->rankingValue()) }}
                                                </td>
                                            @else
                                                <td class="px-5 py-3 font-semibold tabular-nums text-slate-900">
                                                    {{ number_format($bid->amount_credits) }}
                                                </td>
                                            @endif
                                            <td class="px-5 py-3 text-xs text-slate-500">
                                                credit txn #{{ $bid->credit_transaction_id }}
                                            </td>
                                            <td class="px-5 py-3 text-slate-500">
                                                {{ $bid->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i:s') }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-card>
            @endcan

            {{-- ------------------------------------------------ Audit trail --}}
            <x-card title="Lifecycle history" :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Change</th>
                                <th class="px-5 py-3 font-semibold">Reason</th>
                                <th class="px-5 py-3 font-semibold">By</th>
                                <th class="px-5 py-3 font-semibold">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($auction->transitions()->with('causedBy')->orderBy('id')->get() as $transition)
                                <tr>
                                    <td class="px-5 py-3 text-slate-900">
                                        {{ $transition->from_status?->label() ?? 'Created' }}
                                        &rarr; {{ $transition->to_status->label() }}
                                    </td>
                                    <td class="px-5 py-3 text-slate-600">{{ $transition->reason }}</td>
                                    <td class="px-5 py-3 text-slate-500">
                                        {{ $transition->causedBy?->name ?? 'System' }}
                                    </td>
                                    <td class="px-5 py-3 text-slate-500">
                                        {{ $transition->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        {{-- --------------------------------------------------------- Actions --}}
        <div class="space-y-6">
            <x-card title="Status">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Opens</dt>
                        <dd class="text-slate-900">
                            {{ $auction->starts_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i')
                               ?? $auction->scheduled_start_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i')
                               ?? 'Not scheduled' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Ends</dt>
                        <dd class="text-slate-900">
                            {{ $auction->ends_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Time left (server)</dt>
                        <dd class="tabular-nums text-slate-900">
                            {{ $secondsRemaining === null ? '—' : $secondsRemaining.' s' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Extensions used</dt>
                        <dd class="tabular-nums text-slate-900">
                            {{ $auction->extensions_applied }} ({{ $auction->extension_seconds_applied }}s)
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Stock reserved</dt>
                        <dd class="tabular-nums text-slate-900">{{ $auction->product->stock_reserved }}</dd>
                    </div>
                </dl>
            </x-card>

            {{-- The projection beside the records it caches. A discrepancy is
                 reported here, never silently repaired. --}}
            <x-card title="Highest-bid projection">
                @if ($projection['matches'])
                    <p class="text-sm text-emerald-700">Agrees with the bid records.</p>
                @else
                    <x-alert variant="danger">
                        The cached highest bid does not match the bid records. Projected bid
                        {{ $projection['projected_highest_bid_id'] ?? 'none' }} against actual
                        {{ $projection['actual_highest_bid_id'] ?? 'none' }};
                        counted {{ $projection['projected_bid_count'] }} against
                        {{ $projection['actual_bid_count'] }}. The bid records are authoritative.
                        Report this rather than editing anything.
                    </x-alert>
                @endif
            </x-card>

            @can('auctions.publish')
                @if ($auction->status->isConfigurable())
                    <x-card title="Publish" subtitle="Publishing reserves one unit of stock.">
                        <div class="space-y-4">
                            <x-button wire:click="start" class="w-full">Open for bidding now</x-button>

                            <div class="border-t border-slate-100 pt-4">
                                <x-field label="Or open at" name="scheduledStart"
                                         :error="$errors->first('scheduledStart')">
                                    <x-input type="datetime-local" wire:model="scheduledStart"
                                             :error="$errors->has('scheduledStart')" />
                                </x-field>

                                <x-button variant="secondary" wire:click="schedule" class="mt-3 w-full">
                                    Schedule
                                </x-button>
                            </div>
                        </div>
                    </x-card>
                @endif

                @if ($auction->status === App\Enums\AuctionStatus::Scheduled)
                    <x-card title="Publish">
                        <x-button wire:click="start" class="w-full">Open for bidding now</x-button>
                    </x-card>
                @endif

                @if ($auction->status->acceptsBids())
                    <x-card title="Close early"
                            subtitle="Stops the clock now. The highest valid credit bid still wins.">
                        <x-button variant="secondary" wire:click="closeNow" class="w-full">
                            Close and resolve the winner
                        </x-button>
                    </x-card>
                @endif
            @endcan

            @can('auctions.cancel')
                @if ($auction->status->canTransitionTo(App\Enums\AuctionStatus::Cancelled))
                    <x-card title="Cancel"
                            subtitle="Releases the reserved unit. Credits already spent bidding stay consumed.">
                        <x-field label="Reason" name="cancelReason" :error="$errors->first('cancelReason')"
                                 hint="Recorded permanently in the auction's history.">
                            <x-input wire:model="cancelReason" placeholder="Why is this being stopped?"
                                     :error="$errors->has('cancelReason')" />
                        </x-field>

                        <x-button variant="danger" wire:click="cancel" class="mt-3 w-full">
                            Cancel auction
                        </x-button>
                    </x-card>
                @endif
            @endcan

            @can('auctions.relist')
                @if ($auction->status->canTransitionTo(App\Enums\AuctionStatus::Relisted))
                    <x-card title="Relist"
                            subtitle="Creates a new draft auction for the same product and marks this one relisted.">
                        <div class="space-y-4">
                            <x-field label="Ruleset" name="relistRulesetId"
                                     :error="$errors->first('relistRulesetId')">
                                <select wire:model="relistRulesetId" id="relistRulesetId"
                                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                    @foreach ($rulesets as $ruleset)
                                        <option value="{{ $ruleset->id }}">
                                            {{ $ruleset->name }} (v{{ $ruleset->version }})
                                        </option>
                                    @endforeach
                                </select>
                            </x-field>

                            <x-field label="Settlement amount (GH₵)" name="relistSettlementAmount"
                                     :error="$errors->first('relistSettlementAmount')"
                                     hint="A fresh decision. Nothing is carried over from this auction.">
                                <x-input wire:model="relistSettlementAmount"
                                         :error="$errors->has('relistSettlementAmount')" />
                            </x-field>

                            <x-button variant="secondary" wire:click="relist" class="w-full">
                                Relist as a new auction
                            </x-button>
                        </div>
                    </x-card>
                @endif
            @endcan
        </div>
    </div>
</x-admin.shell>

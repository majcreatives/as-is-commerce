{{-- One customer, for support.

     What somebody on a call actually needs: what this person bought, what they
     owe, where their packages are, what happened to their money, and whether
     anything of theirs is stuck.

     READ ONLY, ENTIRELY. There is no status control, no balance field, no order
     action and no credit adjustment on this page. Each of those exists on the
     screen that owns it, behind the permission that governs it, and a second
     copy here would be a second implementation of a rule.

     Nothing sensitive appears: no password material, no OTP data, no payment
     credential. --}}

@php
    $timezone = settings()->getString('display_timezone', 'UTC');
@endphp

<x-admin.shell>

    <div class="mb-6">
        <a href="{{ route('admin.customers.index') }}" wire:navigate
           class="text-sm font-semibold text-brand-800 hover:underline">&larr; Customers</a>
    </div>

    <x-page-header
        :title="$customer->name"
        :description="$customer->phone.($customer->email ? ' · '.$customer->email : '')" />

    <div class="mb-6 flex flex-wrap items-center gap-2">
        <x-badge>
            Joined {{ $customer->created_at?->timezone($timezone)->format('j M Y') }}
        </x-badge>

        @if ($customer->referral_code)
            <x-badge classes="bg-brand-50 text-brand-800 ring-brand-200">
                Referral code {{ $customer->referral_code }}
            </x-badge>
        @endif

        @if ($unpaidOrders > 0)
            <x-badge classes="bg-amber-50 text-amber-800 ring-amber-200">
                {{ $unpaidOrders }} {{ Str::plural('order', $unpaidOrders) }} awaiting payment
            </x-badge>
        @endif
    </div>

    {{-- Credits are two figures and never one. What is spendable and what is
         already committed to bids are different facts, and a total would
         describe neither. Neither is money. --}}
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Credit balance</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                <x-credits :amount="$availableCredits" />
            </p>
            <p class="mt-1 text-xs text-slate-500">Available to bid with.</p>
        </x-card>

        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Committed to bids</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                <x-credits :amount="$creditsCommitted" />
            </p>
            <p class="mt-1 text-xs text-slate-500">
                Consumed on live auctions. Spent, not held &mdash; they do not come back.
            </p>
        </x-card>

        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Spent</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                <x-money :amount="$totalSpent" />
            </p>
            <p class="mt-1 text-xs text-slate-500">Across orders with a verified payment.</p>
        </x-card>

        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Auctions</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900">
                {{ number_format($auctionsWon) }} won
            </p>
            <p class="mt-1 text-xs text-slate-500">
                Bid on {{ number_format($auctionsBidOn) }},
                {{ number_format($liveAuctions) }} still running.
            </p>
        </x-card>
    </div>

    {{-- Orders ------------------------------------------------------------ --}}
    @can('orders.view')
        <x-card class="mb-6" title="Orders" subtitle="The twenty most recent." :padded="false">
            @if ($orders->isEmpty())
                <div class="p-5">
                    <x-empty-state title="No orders" description="This customer has not ordered anything." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Order</th>
                                <th class="px-5 py-3 font-semibold">Total</th>
                                <th class="px-5 py-3 font-semibold">Status</th>
                                <th class="px-5 py-3 font-semibold">Delivery</th>
                                <th class="px-5 py-3 font-semibold">Placed</th>
                                <th class="px-5 py-3"><span class="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($orders as $order)
                                <tr class="{{ $order->isFulfilmentBlocked() ? 'bg-red-50/60' : '' }}">
                                    <td class="px-5 py-3">
                                        <p class="font-semibold text-slate-900">{{ $order->order_number }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ $order->items->count() }}
                                            {{ Str::plural('item', $order->items->count()) }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-3 tabular-nums text-slate-900">
                                        <x-money :amount="$order->total()" />
                                        @if ($order->refunds->isNotEmpty())
                                            <p class="mt-1 text-xs text-slate-500">
                                                {{ $order->refunds->count() }}
                                                {{ Str::plural('refund', $order->refunds->count()) }}
                                            </p>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        <x-badge :classes="$order->status->badgeClasses()">
                                            {{ $order->status->label() }}
                                        </x-badge>

                                        @if ($order->isFulfilmentBlocked())
                                            {{-- Paid, and nothing could be handed
                                                 over. What is owed is a decision
                                                 for a person, not something this
                                                 screen offers to settle. --}}
                                            <p class="mt-1 max-w-xs text-xs text-red-700">
                                                Blocked: {{ $order->fulfilment_blocked_reason }}
                                            </p>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3">
                                        @if ($order->delivery)
                                            <x-badge :classes="$order->delivery->status->badgeClasses()">
                                                {{ $order->delivery->status->label() }}
                                            </x-badge>
                                        @else
                                            <span class="text-xs text-slate-500">—</span>
                                        @endif
                                    </td>

                                    <td class="px-5 py-3 text-slate-500">
                                        {{ $order->placed_at?->timezone($timezone)->format('j M, H:i') ?? '—' }}
                                    </td>

                                    <td class="px-5 py-3 text-right">
                                        <x-button size="sm" variant="ghost"
                                                  href="{{ route('admin.orders.show', $order) }}" wire:navigate>
                                            Open
                                        </x-button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    @endcan

    {{-- Deliveries -------------------------------------------------------- --}}
    @can('deliveries.view')
        <x-card class="mb-6" title="Deliveries" subtitle="Where this customer's packages are." :padded="false">
            @if ($deliveries->isEmpty())
                <div class="p-5">
                    <x-empty-state title="Nothing to deliver" description="No delivery has been opened for this customer." />
                </div>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($deliveries as $delivery)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="font-semibold text-slate-900">{{ $delivery->reference }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $delivery->order?->order_number }}
                                    @if ($delivery->tracking_reference)
                                        &middot; tracking {{ $delivery->tracking_reference }}
                                    @endif
                                    @if ($delivery->attempts > 0)
                                        &middot; {{ $delivery->attempts }}
                                        {{ Str::plural('attempt', $delivery->attempts) }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex items-center gap-3">
                                <x-badge :classes="$delivery->status->badgeClasses()">
                                    {{ $delivery->status->label() }}
                                </x-badge>

                                @if ($delivery->order && auth()->user()->can('orders.view'))
                                    <x-button size="sm" variant="ghost"
                                              href="{{ route('admin.orders.show', $delivery->order) }}" wire:navigate>
                                        Open order
                                    </x-button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endcan

    {{-- Credits ----------------------------------------------------------- --}}
    @can('wallets.inspect')
        <x-card class="mb-6" title="Recent credit movements"
                subtitle="The ten most recent. The full ledger lives on the wallet screen." :padded="false">
            @if ($recentCredits->isEmpty())
                <div class="p-5">
                    <x-empty-state title="No credit movements" description="This customer has never bought or spent credits." />
                </div>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($recentCredits as $transaction)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="font-semibold text-slate-900">{{ $transaction->type->label() }}</p>
                                @if ($transaction->description)
                                    <p class="text-xs text-slate-500">{{ $transaction->description }}</p>
                                @endif
                            </div>

                            <div class="text-right">
                                {{-- A count of credits, never money. --}}
                                <p class="tabular-nums font-semibold {{ $transaction->amount < 0 ? 'text-red-700' : 'text-emerald-700' }}">
                                    {{ $transaction->signedAmount() }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $transaction->created_at->timezone($timezone)->format('j M, H:i') }}
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="border-t border-slate-100 px-5 py-4">
                <x-button size="sm" variant="secondary"
                          href="{{ route('admin.wallets.show', $customer) }}" wire:navigate>
                    Open the wallet
                </x-button>
            </div>
        </x-card>
    @endcan

    {{-- Bids -------------------------------------------------------------- --}}
    @can('auctions.view')
        <x-card class="mb-6" title="Bids" subtitle="The twenty most recent." :padded="false">
            @if ($bids->isEmpty())
                <div class="p-5">
                    <x-empty-state title="No bids" description="This customer has never bid." />
                </div>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($bids as $bid)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="font-semibold text-slate-900">
                                    {{ $bid->auction?->product?->name ?? 'Removed product' }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    Auction #{{ $bid->auction_id }}
                                    &middot; {{ $bid->created_at->timezone($timezone)->format('j M, H:i') }}
                                </p>
                            </div>

                            <div class="flex items-center gap-3">
                                <span class="tabular-nums font-semibold text-slate-900">
                                    <x-credits :amount="$bid->amount_credits" />
                                </span>

                                @if ($bid->auction)
                                    <x-button size="sm" variant="ghost"
                                              href="{{ route('admin.auctions.show', $bid->auction) }}" wire:navigate>
                                        Open
                                    </x-button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endcan

    {{-- Referrals --------------------------------------------------------- --}}
    @can('referrals.view')
        <x-card class="mb-6" title="Referrals" :padded="false">
            <div class="px-5 py-4">
                @if ($referredBy)
                    <p class="text-sm text-slate-700">
                        Introduced by
                        <span class="font-semibold">{{ $referredBy->referrer?->name ?? 'Removed account' }}</span>
                        with code <span class="font-mono">{{ $referredBy->code_used }}</span>,
                        {{ $referredBy->attributed_at->timezone($timezone)->format('j M Y') }}.
                    </p>
                @else
                    <p class="text-sm text-slate-500">Not introduced by anybody.</p>
                @endif
            </div>

            @if ($referralsMade->isNotEmpty())
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    @foreach ($referralsMade as $referral)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div>
                                <p class="font-semibold text-slate-900">
                                    {{ $referral->referred?->name ?? 'Removed account' }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $referral->attributed_at->timezone($timezone)->format('j M Y') }}
                                    @if ($referral->reward_credits)
                                        &middot; rewarded <x-credits :amount="$referral->reward_credits" />
                                    @endif
                                </p>
                            </div>

                            <x-badge :classes="$referral->status->badgeClasses()">
                                {{ $referral->status->label() }}
                            </x-badge>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endcan

    {{-- Notifications ------------------------------------------------------ --}}
    @can('notifications.inspect')
        <x-card class="mb-6" title="What the platform told them" subtitle="The ten most recent." :padded="false">
            @if ($notifications->isEmpty())
                <div class="p-5">
                    <x-empty-state title="Nothing sent" description="No notification has been raised for this customer." />
                </div>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($notifications as $notification)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900">{{ $notification->title }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $notification->created_at?->timezone($timezone)->format('j M, H:i') }}
                                    &middot; {{ $notification->isUnread() ? 'Unread' : 'Read' }}
                                </p>
                            </div>

                            @if ($notification->mailFailed())
                                <x-badge classes="bg-red-50 text-red-800 ring-red-200">Email failed</x-badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    @endcan

    <p class="mt-8 text-xs text-slate-500">
        This screen only reads. Refunding, retrying a delivery, adjusting credits and every other
        action live on the pages that own those records, behind the permissions that govern them.
    </p>
</x-admin.shell>

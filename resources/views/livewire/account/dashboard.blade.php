{{-- One customer's own account, at a glance.

     Every figure here is read from the table that owns it. Nothing on this
     page computes anything, and no two figures are ever combined into one that
     would mean less than either. --}}

<div>
    <x-page-header
        :title="'Welcome back' . (auth()->user()->name ? ', ' . auth()->user()->name : '')"
        description="Your credits, your bids, and everything on its way to you." />

    {{-- Things the customer has to act on, before anything else on the page.
         A settlement deadline runs, and a winner who misses it forfeits. --}}
    @if ($awaitingSettlement->isNotEmpty())
        <x-alert variant="warning" class="mb-6" role="status">
            <p class="font-semibold">
                You won {{ $awaitingSettlement->count() }}
                {{ Str::plural('auction', $awaitingSettlement->count()) }} awaiting settlement.
            </p>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($awaitingSettlement as $order)
                    <li>
                        <a href="{{ route('checkout.show', $order) }}" wire:navigate class="font-semibold underline">
                            {{ $order->item()?->product_name_snapshot ?? $order->order_number }}
                        </a>
                        — <x-money :amount="$order->total()" />
                        @if ($order->payment_due_at)
                            , due by
                            {{ $order->payment_due_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-sm">
                Your bid credits are already consumed. This is the separate settlement amount.
            </p>
        </x-alert>
    @endif

    @if ($deliveriesAwaitingAddress > 0)
        <x-alert variant="info" class="mb-6" role="status">
            <strong>{{ $deliveriesAwaitingAddress }}</strong>
            {{ Str::plural('order', $deliveriesAwaitingAddress) }}
            {{ $deliveriesAwaitingAddress === 1 ? 'is' : 'are' }} waiting for a delivery address.
            <a href="{{ route('orders.index') }}" wire:navigate class="font-semibold underline">Tell us where to send them</a>
        </x-alert>
    @endif

    {{-- The numbers. Credits are a count and money has a symbol, and the two
         kinds of credit are separate cards so neither can be read as the
         other. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Available credits</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                {{ number_format($availableCredits) }}
            </p>
            <p class="mt-1 text-xs text-slate-500">Ready to bid with.</p>
            <x-button href="{{ route('credits.packages') }}" wire:navigate variant="secondary" size="sm" class="mt-3">
                Buy credits
            </x-button>
        </x-card>

        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Credits committed</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                {{ number_format($creditsCommitted) }}
            </p>
            {{-- Said plainly on the dashboard rather than buried. These are
                 spent, and this figure is never added to the one beside it. --}}
            <p class="mt-1 text-xs text-slate-500">
                Consumed on bids. Not returned, whether you won or lost.
            </p>
        </x-card>

        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Active bids</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                {{ number_format($activeBidCount) }}
            </p>
            <p class="mt-1 text-xs text-slate-500">
                {{ $auctionsWon }} {{ Str::plural('auction', $auctionsWon) }} won so far.
            </p>
        </x-card>

        <x-card>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Spent with us</p>
            <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                <x-money :amount="$totalSpent" />
            </p>
            <p class="mt-1 text-xs text-slate-500">
                {{ $unpaidOrders }} {{ Str::plural('order', $unpaidOrders) }} awaiting payment.
            </p>
        </x-card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Auctions you are bidding in" :padded="false">
                @if ($activeBids->isEmpty())
                    <div class="p-5">
                        <x-empty-state
                            title="You haven't bid on anything yet"
                            description="Find something you want and commit credits to it. The highest valid bid wins when the auction closes." />
                        <div class="mt-4 flex justify-center">
                            <x-button href="{{ route('auctions.index') }}" wire:navigate>Explore live auctions</x-button>
                        </div>
                    </div>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($activeBids as $auction)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div class="min-w-0">
                                    <a href="{{ route('auctions.show', $auction) }}" wire:navigate
                                       class="font-semibold text-brand-800 underline">
                                        {{ $auction->product->name }}
                                    </a>
                                    <p class="mt-1 text-sm text-slate-600">
                                        Highest Bid (Credits):
                                        <span class="font-semibold tabular-nums">
                                            {{ number_format($auction->highest_bid_credits ?? 0) }}
                                        </span>
                                        @if ($remaining[$auction->id] !== null)
                                            · closes in
                                            <x-countdown :seconds="$remaining[$auction->id]" :ends-at="$auction->ends_at" />
                                        @endif
                                    </p>
                                </div>

                                @if ($leading[$auction->id] ?? false)
                                    <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-200">
                                        You lead
                                    </x-badge>
                                @else
                                    <x-badge classes="bg-accent-50 text-accent-900 ring-accent-200">
                                        Outbid
                                    </x-badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Recent orders" :padded="false">
                @if ($recentOrders->isEmpty())
                    <div class="p-5">
                        <x-empty-state
                            title="No orders yet"
                            description="Anything you buy outright or win at auction will appear here." />
                    </div>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($recentOrders as $order)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div class="min-w-0">
                                    <a href="{{ route('orders.show', $order) }}" wire:navigate
                                       class="font-semibold text-brand-800 underline">
                                        {{ $order->item()?->product_name_snapshot ?? $order->order_number }}
                                    </a>
                                    <p class="mt-1 text-sm text-slate-600">
                                        {{ $order->source->label() }} ·
                                        <x-money :amount="$order->total()" />
                                    </p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <x-badge :classes="$order->status->badgeClasses()">
                                        {{ $order->status->label() }}
                                    </x-badge>
                                    @if ($order->delivery)
                                        <x-badge :classes="$order->delivery->status->badgeClasses()">
                                            {{ $order->delivery->status->customerLabel() }}
                                        </x-badge>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <div class="border-t border-slate-100 px-5 py-3">
                        <a href="{{ route('orders.index') }}" wire:navigate
                           class="text-sm font-semibold text-brand-800 underline">All orders</a>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="On its way" :padded="false">
                @if ($activeDeliveries->isEmpty())
                    <div class="p-5">
                        <x-empty-state
                            title="Nothing in transit"
                            description="Deliveries appear here once we start preparing an order." />
                    </div>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($activeDeliveries as $delivery)
                            <li class="px-5 py-4">
                                <a href="{{ route('orders.tracking', $delivery->order) }}" wire:navigate
                                   class="text-sm font-semibold text-brand-800 underline">
                                    {{ $delivery->order->item()?->product_name_snapshot ?? $delivery->reference }}
                                </a>
                                <p class="mt-1">
                                    <x-badge :classes="$delivery->status->badgeClasses()">
                                        {{ $delivery->status->customerLabel() }}
                                    </x-badge>
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Notifications">
                @if ($unreadNotifications > 0)
                    <p class="text-sm text-slate-700">
                        You have <strong>{{ number_format($unreadNotifications) }}</strong> unread
                        {{ Str::plural('notification', $unreadNotifications) }}.
                    </p>
                @else
                    <p class="text-sm text-slate-600">Nothing unread.</p>
                @endif

                <x-button href="{{ route('notifications.index') }}" wire:navigate
                          variant="secondary" size="sm" class="mt-3">
                    Open notifications
                </x-button>
            </x-card>

            <x-card title="Your account">
                <ul class="space-y-2 text-sm">
                    <li><a href="{{ route('wallet') }}" wire:navigate class="text-brand-800 underline">Credit wallet</a></li>
                    <li><a href="{{ route('credits.history') }}" wire:navigate class="text-brand-800 underline">Credit purchases</a></li>
                    <li><a href="{{ route('addresses.index') }}" wire:navigate class="text-brand-800 underline">Delivery addresses</a></li>
                    <li><a href="{{ route('profile.edit') }}" wire:navigate class="text-brand-800 underline">Profile and preferences</a></li>
                </ul>
            </x-card>
        </div>
    </div>
</div>

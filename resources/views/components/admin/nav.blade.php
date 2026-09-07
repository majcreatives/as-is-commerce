{{-- Secondary navigation for the administration area.

     Grouped rather than flat. Twenty-one links in one wrapping row gave no clue
     which screen answers which question, and the ordering was the order the
     stages were built in — meaningful to nobody using it.

     Each link is hidden unless the signed-in administrator holds the permission
     behind it, and a group with nothing visible in it does not render its
     heading either. --}}

@php
    // label => [route name, active pattern, permission]
    $groups = [
        'Operations' => [
            ['Overview', 'admin.dashboard', 'admin.dashboard', 'admin.dashboard.view'],
            ['Exceptions', 'admin.exceptions', 'admin.exceptions', 'exceptions.view'],
            ['Search', 'admin.search', 'admin.search', 'admin.dashboard.view'],
            ['Audit', 'admin.audit', 'admin.audit', 'audit.view'],
        ],
        'Commerce' => [
            ['Auctions', 'admin.auctions.index', 'admin.auctions.*', 'auctions.view'],
            ['Orders', 'admin.orders.index', 'admin.orders.*', 'orders.view'],
            ['Fulfilment', 'admin.fulfilment', 'admin.fulfilment', 'deliveries.view'],
            ['Customers', 'admin.customers.index', 'admin.customers.*', 'customers.view'],
        ],
        'Money' => [
            ['Payments', 'admin.payments', 'admin.payments', 'order_payments.view'],
            ['Refunds', 'admin.refunds', 'admin.refunds', 'refunds.view'],
            ['Wallets', 'admin.wallets.index', 'admin.wallets.*', 'wallets.inspect'],
            ['Purchases', 'admin.credit-purchases', 'admin.credit-purchases', 'credit_purchases.view'],
            ['Payment events', 'admin.payment-events', 'admin.payment-events', 'payment_events.view'],
        ],
        'Catalogue' => [
            ['Products', 'admin.products', 'admin.products', 'products.view'],
            ['Categories', 'admin.taxonomy', 'admin.taxonomy', 'categories.view'],
            ['Inventory', 'admin.inventory', 'admin.inventory', 'inventory.view'],
        ],
        'Configuration' => [
            ['Auction rulesets', 'admin.rulesets.index', 'admin.rulesets.*', 'auction_rulesets.view'],
            ['Credit packages', 'admin.credit-packages', 'admin.credit-packages', 'credit_packages.view'],
            ['Referrals', 'admin.referrals', 'admin.referrals', 'referrals.view'],
            ['Notifications', 'admin.notifications', 'admin.notifications', 'notifications.inspect'],
            ['Settings', 'admin.settings', 'admin.settings', 'settings.view'],
        ],
    ];
@endphp

<nav class="mb-6 space-y-2 border-b border-slate-200 pb-3" aria-label="Administration">
    @foreach ($groups as $heading => $links)
        @php
            $visible = array_values(array_filter(
                $links,
                fn (array $link): bool => auth()->user()?->can($link[3]) ?? false,
            ));
        @endphp

        @if ($visible !== [])
            <div class="flex flex-wrap items-center gap-1">
                {{-- Full width on a phone, where a 6rem label column would
                     leave the links about fourteen rem to wrap inside. --}}
                <span class="mr-1 w-full shrink-0 text-xs font-semibold uppercase tracking-wide text-slate-400 sm:w-24">
                    {{ $heading }}
                </span>

                @foreach ($visible as [$label, $routeName, $pattern, $permission])
                    <x-nav-link href="{{ route($routeName) }}" wire:navigate
                                :active="request()->routeIs($pattern)">{{ $label }}</x-nav-link>
                @endforeach
            </div>
        @endif
    @endforeach
</nav>

{{-- Secondary navigation for the administration area.

     Grouped rather than flat. Twenty-one links in one wrapping row gave no clue
     which screen answers which question, and the ordering was the order the
     stages were built in — meaningful to nobody using it.

     On desktop this renders as the left rail of the `<x-admin.shell>` frame; on
     a phone the same links collapse into a single horizontally scrollable strip
     above the content, where a full sidebar would simply run off the screen.

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
            ['Store wallets', 'admin.store-wallets.index', 'admin.store-wallets.*', 'wallets.inspect'],
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

    $visibleGroups = [];
    foreach ($groups as $heading => $links) {
        $visible = array_values(array_filter(
            $links,
            fn (array $link): bool => auth()->user()?->can($link[3]) ?? false,
        ));

        if ($visible !== []) {
            $visibleGroups[$heading] = $visible;
        }
    }
@endphp

<nav aria-label="Administration" class="mb-6 lg:mb-0 lg:self-start">
    {{-- Phone: one scrolling strip, no headings. Group headings would be a
         second dimension a finger cannot hold on to. --}}
    <div class="-mx-4 overflow-x-auto px-4 lg:hidden">
        <div class="flex min-w-max gap-1">
            @foreach ($visibleGroups as $links)
                @foreach ($links as [$label, $routeName, $pattern])
                    @php($isActive = request()->routeIs($pattern))
                    <a href="{{ route($routeName) }}" wire:navigate
                       @class([
                           'whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition',
                           'bg-brand-50 font-semibold text-brand-800' => $isActive,
                           'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $isActive,
                       ])>
                        {{ $label }}
                    </a>
                @endforeach
            @endforeach
        </div>
    </div>

    {{-- Desktop: a sticky left rail, grouped under the headings the screens
         were grouped by when they were built. --}}
    <div class="hidden lg:sticky lg:top-8 lg:block">
        <div class="space-y-6">
            @foreach ($visibleGroups as $heading => $links)
                <div>
                    <p class="mb-1 px-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                        {{ $heading }}
                    </p>

                    <div class="space-y-0.5">
                        @foreach ($links as [$label, $routeName, $pattern])
                            @php($isActive = request()->routeIs($pattern))
                            <a href="{{ route($routeName) }}" wire:navigate
                               @class([
                                   'relative flex items-center rounded-md px-3 py-2 text-sm font-medium transition',
                                   'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                                   'bg-brand-50 font-semibold text-brand-800' => $isActive,
                                   'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $isActive,
                               ])>
                                @if ($isActive)
                                    <span aria-hidden="true"
                                          class="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-full bg-brand-600"></span>
                                @endif
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</nav>
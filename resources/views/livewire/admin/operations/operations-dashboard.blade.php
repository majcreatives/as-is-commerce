{{-- What the platform looks like right now.

     Every number is counted from the table that owns it, at the moment this
     page renders. Nothing here is cached and nothing is a rollup, because a
     counter that can drift is worse than no counter.

     Read and route: each figure links to the screen that owns the records, and
     every action lives there behind its own permission. A card whose screen
     this administrator may not open is not rendered, so nothing on the page is
     a link to a 403. --}}

<div>
    <x-admin.nav />

    <x-page-header
        title="Operations"
        description="What the platform looks like right now, counted from the records themselves." />

    {{-- What needs a person, before anything else on the page. --}}
    @if (($exceptions['total'] ?? 0) > 0)
        <x-alert variant="warning" class="mb-6">
            <p class="font-semibold">
                {{ number_format($exceptions['total']) }}
                {{ Str::plural('item', $exceptions['total']) }} needing attention.
            </p>
            <p class="mt-1">
                Paid orders nobody can deliver against, refunds that failed, packages that came
                back, and records that disagree with each other.
            </p>
            <x-button href="{{ route('admin.exceptions') }}" wire:navigate size="sm" class="mt-3">
                Open the exception centre
            </x-button>
        </x-alert>
    @elseif ($exceptions !== [])
        <x-alert variant="success" class="mb-6">
            Nothing needs attention right now.
        </x-alert>
    @endif

    @php
        // A card is a label, a number, where to look, and the permission that
        // guards the looking. Nothing on this page is a chart: an operator
        // needs a count and somewhere to go.
        $sections = [
            'Commerce' => [
                ['Live auctions', $metrics['commerce']['auctions_live'], 'admin.auctions.index', 'auctions.view'],
                ['Ending within 6 hours', $metrics['commerce']['auctions_ending_soon'], 'admin.auctions.index', 'auctions.view'],
                ['Scheduled auctions', $metrics['commerce']['auctions_scheduled'], 'admin.auctions.index', 'auctions.view'],
                ['Active products', $metrics['commerce']['products_active'], 'admin.products', 'products.view'],
                ['Awaiting payment', $metrics['commerce']['orders_awaiting_payment'], 'admin.orders.index', 'orders.view'],
                ['Paid orders', $metrics['commerce']['orders_paid'], 'admin.orders.index', 'orders.view'],
                ['Processing', $metrics['commerce']['orders_processing'], 'admin.orders.index', 'orders.view'],
                ['Fulfilled', $metrics['commerce']['orders_fulfilled'], 'admin.orders.index', 'orders.view'],
            ],
            'Money' => [
                ['Successful payments', $metrics['financial']['payments_successful'], 'admin.payments', 'order_payments.view'],
                ['Payments in flight', $metrics['financial']['payments_pending'], 'admin.payments', 'order_payments.view'],
                ['Failed payments', $metrics['financial']['payments_failed'], 'admin.payments', 'order_payments.view'],
                ['Refunds processing', $metrics['financial']['refunds_processing'], 'admin.refunds', 'refunds.view'],
                ['Refunds failed', $metrics['financial']['refunds_failed'], 'admin.refunds', 'refunds.view'],
                ['Awaiting a recovery decision', $metrics['financial']['awaiting_recovery'], 'admin.refunds', 'refunds.view'],
            ],
            'Fulfilment' => [
                ['To prepare', $metrics['operations']['deliveries_pending'], 'admin.fulfilment', 'deliveries.view'],
                ['Preparing', $metrics['operations']['deliveries_preparing'], 'admin.fulfilment', 'deliveries.view'],
                ['Ready for dispatch', $metrics['operations']['deliveries_ready'], 'admin.fulfilment', 'deliveries.view'],
                ['Dispatched', $metrics['operations']['deliveries_dispatched'], 'admin.fulfilment', 'deliveries.view'],
                ['Out for delivery', $metrics['operations']['deliveries_out'], 'admin.fulfilment', 'deliveries.view'],
                ['Failed deliveries', $metrics['operations']['deliveries_failed'], 'admin.fulfilment', 'deliveries.view'],
                ['Waiting on an address', $metrics['operations']['deliveries_awaiting_address'], 'admin.fulfilment', 'deliveries.view'],
                ['Orders blocked', $metrics['operations']['orders_blocked'], 'admin.orders.index', 'orders.view'],
            ],
            'Customers' => [
                ['Customers', $metrics['customers']['customers_total'], 'admin.customers.index', 'customers.view'],
                ['Joined in 30 days', $metrics['customers']['customers_recent'], 'admin.customers.index', 'customers.view'],
                ['Referrals attributed', $metrics['customers']['referrals_attributed'], 'admin.referrals', 'referrals.view'],
                ['Referrals rewarded', $metrics['customers']['referrals_rewarded'], 'admin.referrals', 'referrals.view'],
            ],
            'Inventory' => [
                ['Nothing available to buy', $metrics['inventory']['products_no_available_stock'], 'admin.inventory', 'inventory.view'],
                ['Units on hand', $metrics['inventory']['units_on_hand'], 'admin.inventory', 'inventory.view'],
                ['Units reserved', $metrics['inventory']['units_reserved'], 'admin.inventory', 'inventory.view'],
                ['Impossible stock', $metrics['inventory']['stock_anomalies'], 'admin.exceptions', 'exceptions.view'],
            ],
        ];
    @endphp

    {{-- Money taken and money returned, side by side. Two separate facts, and
         never netted into one figure that would describe neither of them. --}}
    @canany(['order_payments.view', 'refunds.view'])
        <div class="mb-8 grid gap-4 sm:grid-cols-2">
            @can('order_payments.view')
                <x-card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Collected</p>
                    <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                        <x-money :amount="$collected" />
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        Across every payment the provider confirmed. A historical fact, so a
                        later refund does not move it.
                    </p>
                </x-card>
            @endcan

            @can('refunds.view')
                <x-card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Refunded</p>
                    <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                        <x-money :amount="$refunded" />
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        Across refunds the provider confirmed. Its own figure, never subtracted
                        from the one beside it.
                    </p>
                </x-card>
            @endcan
        </div>
    @endcanany

    @foreach ($sections as $heading => $cards)
        @php
            $visible = array_values(array_filter(
                $cards,
                fn (array $card): bool => auth()->user()->can($card[3]),
            ));
        @endphp

        @if ($visible !== [])
            <section class="mt-8">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ $heading }}</h2>

                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($visible as [$label, $value, $routeName, $permission])
                        <a href="{{ route($routeName) }}" wire:navigate
                           class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand-300 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                            <span class="block text-2xl font-bold tabular-nums text-slate-900">
                                {{ number_format($value) }}
                            </span>
                            <span class="mt-1 block text-xs font-medium text-slate-600">{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach

    {{-- Scheduler health. Last-run timestamps read from the cache; the
         commands stamp these after every successful pass. Absence means the
         sweep has never run or the cache was cleared, both of which are
         information for a person, not an anomaly to act on. --}}
    @can('admin.dashboard.view')
        <section class="mt-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Scheduler</h2>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($sweeps as $sweep)
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <span class="block text-sm font-semibold text-slate-900">{{ $sweep['label'] }}</span>
                        @if ($sweep['last_run_at'])
                            <span class="mt-1 block text-xs text-slate-600">
                                Last ran {{ \Illuminate\Support\Carbon::parse($sweep['last_run_at'])->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i:s') }}
                            </span>
                        @else
                            <span class="mt-1 block text-xs text-slate-400">Not yet run</span>
                        @endif
                        @if ($sweep['cadence_minutes'] > 0)
                            <span class="mt-1 block text-xs text-slate-400">Every {{ $sweep['cadence_minutes'] }} {{ Str::plural('minute', $sweep['cadence_minutes']) }}</span>
                        @else
                            <span class="mt-1 block text-xs text-slate-400">Manual</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endcan

    <p class="mt-10 text-xs text-slate-500">
        Every figure is counted from the records themselves when this page loads. Nothing here is
        cached, and nothing on this screen changes anything &mdash; each card links to the place
        that owns the records behind it.
    </p>
</div>

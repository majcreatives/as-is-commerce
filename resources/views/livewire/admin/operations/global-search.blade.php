{{-- One box for whatever reference somebody is holding.

     Every group is rendered only for an administrator holding the permission
     that governs that kind of record, and every link goes to a page that checks
     its own. Searching is not a way around authorization. --}}

<x-admin.shell>

    <x-page-header
        title="Search"
        description="An order number, a phone number, a provider reference, a delivery reference, a SKU." />

    <x-card class="mb-6">
        <x-field label="Find" name="q">
            <input type="search" id="q" wire:model.live.debounce.400ms="q"
                   maxlength="{{ \App\Domain\Operations\Queries\OperationsSearch::MAX_LENGTH }}"
                   autocomplete="off" autofocus
                   placeholder="ORD-000123, +233241234567, paystack reference, SKU…"
                   class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
        </x-field>

        <p class="mt-2 text-xs text-slate-500">
            Phone numbers are matched however they are typed &mdash; 024 123 4567 finds
            +233241234567.
        </p>
    </x-card>

    @if ($term === '')
        <x-empty-state
            title="Nothing searched yet"
            description="Type a reference above. Results are limited to ten of each kind, so search for the record rather than browsing." />
    @elseif ($tooShort)
        <x-alert variant="info">
            Two characters at least. A single letter matches most of the catalogue and helps nobody.
        </x-alert>
    @elseif ($results === [])
        <x-empty-state
            title="Nothing matched"
            description="No order, customer, product, auction, payment, refund, delivery or referral matches that." />
    @else
        @php
            // Each group names the permission that guards it, so a narrower
            // administrative role sees only what it may already open.
            $groups = [
                'orders' => ['Orders', 'orders.view'],
                'customers' => ['Customers', 'customers.view'],
                'products' => ['Products', 'products.view'],
                'auctions' => ['Auctions', 'auctions.view'],
                'payments' => ['Payment attempts', 'order_payments.view'],
                'refunds' => ['Refunds', 'refunds.view'],
                'deliveries' => ['Deliveries', 'deliveries.view'],
                'referrals' => ['Referrals', 'referrals.view'],
            ];
        @endphp

        @foreach ($groups as $key => [$heading, $permission])
            @if (isset($results[$key]) && auth()->user()->can($permission))
                <section class="mb-6">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-500">
                        {{ $heading }}
                        <span class="ml-1 font-normal normal-case text-slate-400">
                            ({{ $results[$key]->count() }})
                        </span>
                    </h2>

                    <x-card :padded="false">
                        <ul class="divide-y divide-slate-100">
                            @foreach ($results[$key] as $result)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                    <div class="min-w-0">
                                        @switch($key)
                                            @case('orders')
                                                <p class="font-semibold text-slate-900">{{ $result->order_number }}</p>
                                                <p class="text-xs text-slate-500">
                                                    {{ $result->user?->name ?? 'Removed account' }}
                                                    &middot; <x-money :amount="$result->total()" />
                                                </p>
                                                @break

                                            @case('customers')
                                                <p class="font-semibold text-slate-900">{{ $result->name }}</p>
                                                <p class="text-xs text-slate-500">{{ $result->phone }}</p>
                                                @break

                                            @case('products')
                                                <p class="font-semibold text-slate-900">{{ $result->name }}</p>
                                                <p class="text-xs text-slate-500">
                                                    {{ $result->sku }}
                                                    @if ($result->brand) &middot; {{ $result->brand->name }} @endif
                                                </p>
                                                @break

                                            @case('auctions')
                                                <p class="font-semibold text-slate-900">
                                                    {{ $result->product?->name ?? 'Removed product' }}
                                                </p>
                                                <p class="text-xs text-slate-500">Auction #{{ $result->id }}</p>
                                                @break

                                            @case('payments')
                                                <p class="font-mono text-sm font-semibold text-slate-900">
                                                    {{ $result->provider_reference }}
                                                </p>
                                                <p class="text-xs text-slate-500">
                                                    {{ $result->order?->order_number }}
                                                    &middot; <x-money :amount="$result->amount()" />
                                                </p>
                                                @break

                                            @case('refunds')
                                                <p class="font-mono text-sm font-semibold text-slate-900">
                                                    {{ $result->provider_reference ?? 'Not sent yet' }}
                                                </p>
                                                <p class="text-xs text-slate-500">
                                                    {{ $result->order?->order_number }}
                                                    &middot; <x-money :amount="$result->amount()" />
                                                </p>
                                                @break

                                            @case('deliveries')
                                                <p class="font-semibold text-slate-900">{{ $result->reference }}</p>
                                                <p class="text-xs text-slate-500">
                                                    {{ $result->order?->order_number }}
                                                    @if ($result->tracking_reference)
                                                        &middot; tracking {{ $result->tracking_reference }}
                                                    @endif
                                                </p>
                                                @break

                                            @case('referrals')
                                                <p class="font-semibold text-slate-900">{{ $result->code_used }}</p>
                                                <p class="text-xs text-slate-500">
                                                    {{ $result->referrer?->name ?? 'Removed account' }}
                                                    &rarr; {{ $result->referred?->name ?? 'Removed account' }}
                                                </p>
                                                @break
                                        @endswitch
                                    </div>

                                    <div class="flex shrink-0 items-center gap-3">
                                        @if (isset($result->status) && $result->status instanceof \BackedEnum && method_exists($result->status, 'badgeClasses'))
                                            <x-badge :classes="$result->status->badgeClasses()">
                                                {{ $result->status->label() }}
                                            </x-badge>
                                        @endif

                                        @php
                                            // Where to look. A kind with no detail
                                            // page links to the screen that lists it,
                                            // rather than nowhere.
                                            $href = match ($key) {
                                                'orders' => route('admin.orders.show', $result),
                                                'customers' => route('admin.customers.show', $result),
                                                'products' => route('admin.products'),
                                                'auctions' => route('admin.auctions.show', $result),
                                                'payments' => $result->order
                                                    ? route('admin.orders.show', $result->order)
                                                    : route('admin.payments'),
                                                'refunds' => $result->order
                                                    ? route('admin.orders.show', $result->order)
                                                    : route('admin.refunds'),
                                                'deliveries' => $result->order
                                                    ? route('admin.orders.show', $result->order)
                                                    : route('admin.fulfilment'),
                                                'referrals' => route('admin.referrals'),
                                            };
                                        @endphp

                                        <x-button size="sm" variant="ghost" href="{{ $href }}" wire:navigate>
                                            Open
                                        </x-button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                </section>
            @endif
        @endforeach
    @endif
</x-admin.shell>

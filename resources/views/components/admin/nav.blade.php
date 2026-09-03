{{-- Secondary navigation for the administration area. Each link is hidden
     unless the signed-in administrator holds the permission behind it. --}}
<nav class="mb-6 flex flex-wrap gap-1 border-b border-slate-200 pb-3" aria-label="Administration">
    <x-nav-link href="{{ route('admin.dashboard') }}" wire:navigate
                :active="request()->routeIs('admin.dashboard')">Overview</x-nav-link>

    @can('auction_rulesets.view')
        <x-nav-link href="{{ route('admin.rulesets.index') }}" wire:navigate
                    :active="request()->routeIs('admin.rulesets.*')">Auction rulesets</x-nav-link>
    @endcan

    @can('wallets.inspect')
        <x-nav-link href="{{ route('admin.wallets.index') }}" wire:navigate
                    :active="request()->routeIs('admin.wallets.*')">Wallets</x-nav-link>
    @endcan

    @can('credit_packages.view')
        <x-nav-link href="{{ route('admin.credit-packages') }}" wire:navigate
                    :active="request()->routeIs('admin.credit-packages')">Credit packages</x-nav-link>
    @endcan

    @can('credit_purchases.view')
        <x-nav-link href="{{ route('admin.credit-purchases') }}" wire:navigate
                    :active="request()->routeIs('admin.credit-purchases')">Purchases</x-nav-link>
    @endcan

    @can('payment_events.view')
        <x-nav-link href="{{ route('admin.payment-events') }}" wire:navigate
                    :active="request()->routeIs('admin.payment-events')">Payment events</x-nav-link>
    @endcan

    @can('settings.view')
        <x-nav-link href="{{ route('admin.settings') }}" wire:navigate
                    :active="request()->routeIs('admin.settings')">Settings</x-nav-link>
    @endcan
</nav>

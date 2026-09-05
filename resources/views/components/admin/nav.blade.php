{{-- Secondary navigation for the administration area. Each link is hidden
     unless the signed-in administrator holds the permission behind it. --}}
<nav class="mb-6 flex flex-wrap gap-1 border-b border-slate-200 pb-3" aria-label="Administration">
    <x-nav-link href="{{ route('admin.dashboard') }}" wire:navigate
                :active="request()->routeIs('admin.dashboard')">Overview</x-nav-link>

    @can('auctions.view')
        <x-nav-link href="{{ route('admin.auctions.index') }}" wire:navigate
                    :active="request()->routeIs('admin.auctions.*')">Auctions</x-nav-link>
    @endcan

    @can('orders.view')
        <x-nav-link href="{{ route('admin.orders.index') }}" wire:navigate
                    :active="request()->routeIs('admin.orders.*')">Orders</x-nav-link>
    @endcan

    @can('auction_rulesets.view')
        <x-nav-link href="{{ route('admin.rulesets.index') }}" wire:navigate
                    :active="request()->routeIs('admin.rulesets.*')">Auction rulesets</x-nav-link>
    @endcan

    @can('wallets.inspect')
        <x-nav-link href="{{ route('admin.wallets.index') }}" wire:navigate
                    :active="request()->routeIs('admin.wallets.*')">Wallets</x-nav-link>
    @endcan

    @can('products.view')
        <x-nav-link href="{{ route('admin.products') }}" wire:navigate
                    :active="request()->routeIs('admin.products')">Products</x-nav-link>
    @endcan

    @can('categories.view')
        <x-nav-link href="{{ route('admin.taxonomy') }}" wire:navigate
                    :active="request()->routeIs('admin.taxonomy')">Categories</x-nav-link>
    @endcan

    @can('inventory.view')
        <x-nav-link href="{{ route('admin.inventory') }}" wire:navigate
                    :active="request()->routeIs('admin.inventory')">Inventory</x-nav-link>
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

    @can('deliveries.view')
        <x-nav-link href="{{ route('admin.fulfilment') }}" wire:navigate
                    :active="request()->routeIs('admin.fulfilment')">Fulfilment</x-nav-link>
    @endcan

    @can('refunds.view')
        <x-nav-link href="{{ route('admin.refunds') }}" wire:navigate
                    :active="request()->routeIs('admin.refunds')">Refunds</x-nav-link>
    @endcan

    @can('notifications.inspect')
        <x-nav-link href="{{ route('admin.notifications') }}" wire:navigate
                    :active="request()->routeIs('admin.notifications')">Notifications</x-nav-link>
    @endcan

    @can('settings.view')
        <x-nav-link href="{{ route('admin.settings') }}" wire:navigate
                    :active="request()->routeIs('admin.settings')">Settings</x-nav-link>
    @endcan
</nav>

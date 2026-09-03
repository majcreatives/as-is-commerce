{{-- Secondary navigation for the administration area. Each link is hidden
     unless the signed-in administrator holds the permission behind it. --}}
<nav class="mb-6 flex flex-wrap gap-1 border-b border-slate-200 pb-3" aria-label="Administration">
    <x-nav-link href="{{ route('admin.dashboard') }}" wire:navigate
                :active="request()->routeIs('admin.dashboard')">Overview</x-nav-link>

    @can('auction_rulesets.view')
        <x-nav-link href="{{ route('admin.rulesets.index') }}" wire:navigate
                    :active="request()->routeIs('admin.rulesets.*')">Auction rulesets</x-nav-link>
    @endcan

    @can('settings.view')
        <x-nav-link href="{{ route('admin.settings') }}" wire:navigate
                    :active="request()->routeIs('admin.settings')">Settings</x-nav-link>
    @endcan
</nav>

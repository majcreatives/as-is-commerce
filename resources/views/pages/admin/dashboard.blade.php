<x-layouts.app title="Admin">
    <x-admin.nav />

    <x-page-header
        title="Administration"
        description="Restricted to accounts holding the admin or super_admin role." />

    <div class="grid gap-4 sm:grid-cols-2">
        @can('auction_rulesets.view')
            <x-card title="Auction rulesets" subtitle="How auctions behave.">
                <p class="text-sm text-slate-600">
                    Bid cost, duration, closing window, extensions, checkout deadline and fees —
                    all configuration, none of it hard-coded. Auctions take a permanent copy of
                    their ruleset when created.
                </p>

                <div class="mt-4">
                    <x-button href="{{ route('admin.rulesets.index') }}" wire:navigate variant="secondary" size="sm">
                        Manage rulesets
                    </x-button>
                </div>
            </x-card>
        @endcan

        @can('settings.view')
            <x-card title="Settings" subtitle="Application-wide configuration.">
                <p class="text-sm text-slate-600">
                    Site name, currency, display timezone and support contacts.
                </p>

                <div class="mt-4">
                    <x-button href="{{ route('admin.settings') }}" wire:navigate variant="secondary" size="sm">
                        Manage settings
                    </x-button>
                </div>
            </x-card>
        @endcan
    </div>

    <x-card title="Your roles" class="mt-4">
        <div class="flex flex-wrap gap-2">
            @forelse (auth()->user()->getRoleNames() as $role)
                <x-badge classes="bg-brand-50 text-brand-800 ring-brand-200">{{ $role }}</x-badge>
            @empty
                <p class="text-sm text-slate-500">No roles assigned.</p>
            @endforelse
        </div>
    </x-card>
</x-layouts.app>

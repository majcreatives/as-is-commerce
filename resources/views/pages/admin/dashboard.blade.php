<x-layouts.app title="Admin">
    <x-page-header
        title="Administration"
        description="Restricted to accounts holding the admin or super_admin role." />

    <x-alert variant="info" class="mb-6">
        This route exists to prove the authorization boundary works. Administration
        tooling — users, catalog, auctions, credits, payments, audit logs — is built
        in later stages.
    </x-alert>

    <x-card title="Your roles">
        <div class="flex flex-wrap gap-2">
            @forelse (auth()->user()->getRoleNames() as $role)
                <span class="inline-flex items-center rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-800 ring-1 ring-inset ring-brand-200">
                    {{ $role }}
                </span>
            @empty
                <p class="text-sm text-slate-500">No roles assigned.</p>
            @endforelse
        </div>
    </x-card>
</x-layouts.app>

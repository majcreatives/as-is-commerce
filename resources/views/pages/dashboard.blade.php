<x-layouts.app title="Dashboard">
    <x-page-header
        :title="'Welcome back' . (auth()->user()->name ? ', ' . auth()->user()->name : '')"
        description="Your account overview." />

    <div class="grid gap-4 lg:grid-cols-3">
        <x-card title="Account status" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-slate-500">Status</dt>
                    <dd class="font-medium text-slate-900">{{ auth()->user()->status->label() }}</dd>
                </div>

                <div class="flex items-center justify-between gap-4">
                    <dt class="text-slate-500">Phone</dt>
                    <dd class="font-medium text-slate-900">
                        {{ app(\App\Domain\Shared\Phone\PhoneNumberNormalizer::class)->forDisplay(auth()->user()->phone) }}
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4">
                    <dt class="text-slate-500">Phone verified</dt>
                    <dd class="font-medium {{ auth()->user()->hasVerifiedPhone() ? 'text-emerald-700' : 'text-slate-900' }}">
                        {{ auth()->user()->hasVerifiedPhone() ? 'Verified' : 'Not verified' }}
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4">
                    <dt class="text-slate-500">Email</dt>
                    <dd class="font-medium text-slate-900">{{ auth()->user()->email ?? 'Not provided' }}</dd>
                </div>
            </dl>

            @unless (auth()->user()->hasVerifiedPhone())
                <p class="mt-4 border-t border-slate-100 pt-4 text-xs text-slate-500">
                    Phone verification will be required before buying credits or bidding.
                    Verification opens once SMS delivery is enabled.
                </p>
            @endunless
        </x-card>

        <div class="space-y-4 lg:col-span-2">
            <x-card title="Upcoming auctions" subtitle="Auctions you can join.">
                <x-empty-state
                    title="No auctions scheduled"
                    description="Auctions will appear here once the marketplace opens." />
            </x-card>

            <x-card title="Your activity" subtitle="Your bids, wins and credit history.">
                <x-empty-state
                    title="Nothing to show yet"
                    description="Your bidding and credit activity will be listed here as soon as you start participating." />
            </x-card>
        </div>
    </div>
</x-layouts.app>

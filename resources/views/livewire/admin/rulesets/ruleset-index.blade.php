<div>
    <x-admin.nav />

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            title="Auction rulesets"
            description="Named, versioned auction configurations. An auction takes a permanent copy of its ruleset when it is created, so editing or archiving one here never changes an auction that already exists." />

        @can('auction_rulesets.create')
            <x-button href="{{ route('admin.rulesets.create') }}" wire:navigate variant="primary" class="shrink-0">
                New ruleset
            </x-button>
        @endcan
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    @error('lifecycle')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <div class="mb-4 flex items-center gap-3">
        <label for="status-filter" class="text-sm text-slate-600">Status</label>
        <select id="status-filter" wire:model.live="status"
                class="rounded-lg border-0 bg-white py-2 pl-3 pr-8 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600">
            <option value="">All</option>
            @foreach (\App\Enums\RulesetStatus::cases() as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Name</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Version</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Bid cost</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Duration</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Extensions</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Created</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse ($rulesets as $ruleset)
                        <tr class="align-middle">
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $ruleset->name }}</span>
                                @if ($ruleset->is_default)
                                    <x-badge classes="bg-brand-50 text-brand-800 ring-brand-200" class="ml-1.5">Default</x-badge>
                                @endif
                            </td>

                            <td class="px-4 py-3 tabular-nums text-slate-600">v{{ $ruleset->version }}</td>

                            <td class="px-4 py-3">
                                <x-badge :classes="$ruleset->status->badgeClasses()">{{ $ruleset->status->label() }}</x-badge>
                            </td>

                            <td class="px-4 py-3 tabular-nums text-slate-600">
                                {{ $ruleset->bid_cost_credits }} {{ Str::plural('credit', $ruleset->bid_cost_credits) }}
                            </td>

                            <td class="px-4 py-3 tabular-nums text-slate-600">{{ $ruleset->base_duration_seconds }}s</td>

                            <td class="px-4 py-3 text-slate-600">
                                @if ($ruleset->max_extensions > 0 && $ruleset->extension_seconds > 0)
                                    <span class="tabular-nums">
                                        +{{ $ruleset->extension_seconds }}s &times; {{ $ruleset->max_extensions }}
                                    </span>
                                    <span class="block text-xs text-slate-400 tabular-nums">
                                        max {{ $ruleset->max_extension_total_seconds }}s total
                                    </span>
                                @else
                                    <span class="text-slate-400">Disabled</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-slate-500">{{ $ruleset->created_at?->format('j M Y') }}</td>

                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    @if ($ruleset->isEditable())
                                        @can('auction_rulesets.update')
                                            <x-button href="{{ route('admin.rulesets.edit', $ruleset) }}"
                                                      wire:navigate variant="secondary" size="sm">Edit</x-button>
                                        @endcan

                                        @can('auction_rulesets.activate')
                                            <x-button wire:click="activate({{ $ruleset->id }})"
                                                      wire:confirm="Activate {{ $ruleset->name }} v{{ $ruleset->version }}? Any active version of this ruleset will be archived."
                                                      variant="primary" size="sm">Activate</x-button>
                                        @endcan
                                    @else
                                        @can('auction_rulesets.create')
                                            <x-button wire:click="draftNewVersion({{ $ruleset->id }})"
                                                      variant="secondary" size="sm">New version</x-button>
                                        @endcan
                                    @endif

                                    @if ($ruleset->status !== \App\Enums\RulesetStatus::Archived)
                                        @can('auction_rulesets.archive')
                                            <x-button wire:click="archive({{ $ruleset->id }})"
                                                      wire:confirm="Archive {{ $ruleset->name }} v{{ $ruleset->version }}? Existing auctions are unaffected."
                                                      variant="ghost" size="sm">Archive</x-button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10">
                                <x-empty-state
                                    title="No rulesets match this filter"
                                    description="Create a ruleset to define how auctions behave." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($rulesets->hasPages())
        <div class="mt-4">{{ $rulesets->links() }}</div>
    @endif
</div>

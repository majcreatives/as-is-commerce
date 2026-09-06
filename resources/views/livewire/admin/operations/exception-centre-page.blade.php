{{-- Everything that needs a person.

     Read and route. There is no control here that resolves anything, and no
     dismiss button — an exception disappears when the situation it describes
     stops being true, and marking one handled without handling it would defeat
     the only purpose this screen has. --}}

<div>
    <x-admin.nav />

    <x-page-header
        title="Exceptions"
        description="Things the platform cannot resolve on its own. Nothing here is repaired automatically." />

    @php
        $categories = [
            '' => 'Everything',
            'payments' => 'Payments',
            'refunds' => 'Refunds',
            'auctions' => 'Auctions',
            'delivery' => 'Delivery',
            'referrals' => 'Referrals',
            'inventory' => 'Inventory',
        ];
    @endphp

    <div class="mb-6 flex flex-wrap items-center gap-2">
        @foreach ($categories as $value => $label)
            <button type="button" wire:click="$set('category', '{{ $value }}')"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition
                           {{ $category === $value ? 'bg-brand-700 text-white' : 'text-brand-800 hover:bg-brand-50' }}">
                {{ $label }}
                @php $count = $value === '' ? ($counts['total'] ?? 0) : ($counts[$value] ?? 0); @endphp
                @if ($count > 0)
                    <span class="ml-1 rounded-full bg-white/20 px-1.5 text-xs">{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Reconciling a refund against the provider is a network call per
         refund, so it is opt-in. A screen that quietly made fifty API calls on
         every render would be unusable on the day it mattered. --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        @if ($askProviders)
            <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-200">
                Checked against the payment provider
            </x-badge>
        @else
            <x-button variant="secondary" size="sm" wire:click="checkProviders"
                      wire:loading.attr="disabled" wire:target="checkProviders">
                <span wire:loading.remove wire:target="checkProviders">Also check the payment provider</span>
                <span wire:loading wire:target="checkProviders">Checking…</span>
            </x-button>
            <p class="text-xs text-slate-500">
                Refund records are compared locally by default. Checking the provider makes a
                request per settled refund.
            </p>
        @endif
    </div>

    @if (count($exceptions) === 0)
        <x-empty-state
            title="Nothing needs attention"
            description="Every paid order can be delivered against, every refund settled, and no records disagree." />
    @else
        <x-card :padded="false">
            <ul class="divide-y divide-slate-100">
                @foreach ($exceptions as $exception)
                    <li class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-badge :classes="$exception->severity->badgeClasses()">
                                        {{ $exception->severity->label() }}
                                    </x-badge>

                                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">
                                        {{ $exception->category }}
                                    </span>

                                    <span class="font-mono text-xs text-slate-400">{{ $exception->type }}</span>
                                </div>

                                @if ($exception->reference)
                                    <p class="mt-2 font-semibold text-slate-900">{{ $exception->reference }}</p>
                                @endif

                                <p class="mt-1 text-sm text-slate-700">{{ $exception->detail }}</p>

                                @if ($exception->nextAction)
                                    <p class="mt-2 text-xs text-slate-500">
                                        <span class="font-semibold">Next:</span> {{ $exception->nextAction }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-2">
                                @if ($exception->detectedAt)
                                    <span class="text-xs text-slate-500">
                                        {{ \Illuminate\Support\Carbon::parse($exception->detectedAt)->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                    </span>
                                @endif

                                @if ($exception->url)
                                    {{-- The action lives where the record does,
                                         behind the permission that governs it.
                                         Nothing is duplicated here. --}}
                                    <x-button size="sm" variant="ghost" href="{{ $exception->url }}" wire:navigate>
                                        Open
                                    </x-button>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <p class="mt-8 text-xs text-slate-500">
        This screen detects and reports. It repairs nothing, and there is no way to dismiss an
        entry: each one disappears when the situation behind it is actually resolved.
    </p>
</div>

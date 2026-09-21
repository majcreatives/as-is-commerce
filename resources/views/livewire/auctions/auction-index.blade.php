{{-- Every auction a customer can take part in.

     What counts as an opportunity is decided by the query service, so a
     cancelled, forfeited or settled auction cannot appear in the open list.
     Each card shows a credit figure and a cedis figure through different
     components, so the two can never be read as one price. --}}

<div>
    <x-page-header
        title="Auctions"
        description="Bid with credits, or buy outright. The largest total of credits committed wins when an auction closes." />

    {{-- The one thing a bidder most needs to understand, said once, plainly,
         and not in small print. --}}
    <x-alert variant="info" class="mb-6">
        Credits you bid are consumed straight away and are not returned if you do not win.
        <a href="{{ route('how-it-works') }}" wire:navigate class="font-semibold underline">How bidding works</a>
    </x-alert>

    <div class="grid gap-6 lg:grid-cols-4">
        <div class="lg:col-span-1">
            <x-card title="Filter">
                <div class="space-y-4">
                    <x-field label="Search" name="search">
                        <input type="search" id="search" wire:model.live.debounce.400ms="search"
                               placeholder="Product or brand" maxlength="80"
                               class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                    </x-field>

                    <x-field label="Showing" name="filter">
                        <select id="filter" wire:model.live="filter"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            <option value="open">Open to bid</option>
                            <option value="ended">Finished</option>
                            <option value="all">All auctions</option>
                        </select>
                    </x-field>

                    <x-field label="Category" name="category">
                        <select id="category" wire:model.live="category"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            <option value="">All categories</option>
                            @foreach ($categories as $option)
                                <option value="{{ $option->slug }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Condition" name="condition">
                        <select id="condition" wire:model.live="condition"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            <option value="">Any condition</option>
                            @foreach ($conditions as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    @if ($this->hasFilters())
                        <x-button wire:click="clearFilters" variant="secondary" size="sm" class="w-full">
                            Clear filters
                        </x-button>
                    @endif
                </div>
            </x-card>
        </div>

        <div class="lg:col-span-3">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-600" aria-live="polite">
                    {{-- The count, and a word while it is being recounted. --}}
                    <span wire:loading.remove wire:target="search,filter,category,condition,sort">
                        {{ $auctions->total() }} {{ Str::plural('auction', $auctions->total()) }}
                    </span>

                    <span wire:loading wire:target="search,filter,category,condition,sort"
                          class="text-slate-500" role="status">Searching&hellip;</span>
                </p>

                <div class="w-full sm:w-56">
                    <x-field label="Sort by" name="sort">
                        <select id="sort" wire:model.live="sort"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            @foreach ($sorts as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
            </div>

            @if ($auctions->isEmpty())
                <x-empty-state
                    :title="$this->hasFilters() ? 'No auctions match those filters' : 'No auctions open right now'"
                    :description="$this->hasFilters()
                        ? 'Try a broader search, or clear the filters to see everything.'
                        : 'New auctions open regularly. In the meantime you can buy anything in the shop outright.'" />

                @unless ($this->hasFilters())
                    <div class="mt-4 flex justify-center">
                        <x-button href="{{ route('products.index') }}" wire:navigate variant="secondary">
                            Browse the shop
                        </x-button>
                    </div>
                @endunless
            @else
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($auctions as $auction)
                        <x-auction-card :auction="$auction"
                                        :seconds-remaining="$remaining[$auction->id] ?? null" />
                    @endforeach
                </div>

                <div class="mt-6">{{ $auctions->links() }}</div>
            @endif
        </div>
    </div>
</div>

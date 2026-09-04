{{-- The public auction listing.

     Each card shows the highest bid as a count of credits and the product's
     Buy Now price in GH₵. The two are never combined, and the credit figure
     never carries a currency symbol. --}}

<div>
    <x-page-header
        title="Auctions"
        description="Commit credits to bid. The highest valid credit bid wins when an auction closes." />

    <x-alert variant="info" class="mb-6">
        Credits are not cash. A bid of 150 credits is not GH₵150 — it is 150 credits, and they are
        permanently consumed when the bid is accepted. Each credit you spend bidding on an auction
        takes GH₵1 off that product's Buy Now price.
    </x-alert>

    <div class="mb-6 flex flex-wrap gap-1 border-b border-slate-200 pb-3">
        @foreach (['open' => 'Open', 'ended' => 'Ended', 'all' => 'All'] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition
                           {{ $filter === $value ? 'bg-brand-700 text-white' : 'text-brand-800 hover:bg-brand-50' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($auctions->isEmpty())
        <x-empty-state
            title="No auctions to show"
            description="Nothing is listed here because nothing has been created. This page will never show placeholder auctions." />
    @else
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($auctions as $auction)
                <a href="{{ route('auctions.show', $auction) }}" wire:navigate
                   class="group flex flex-col rounded-xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2">

                    <div class="flex aspect-4/3 items-center justify-center rounded-t-xl bg-slate-100">
                        @if ($auction->product->image_path)
                            <img src="{{ $auction->product->image_path }}" alt="{{ $auction->product->name }}"
                                 class="h-full w-full rounded-t-xl object-cover" loading="lazy">
                        @else
                            <span class="text-xs font-medium text-slate-400">No image</span>
                        @endif
                    </div>

                    <div class="flex flex-1 flex-col p-4">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-badge :classes="$auction->status->badgeClasses()">
                                {{ $auction->status->label() }}
                            </x-badge>

                            @if ($auction->endedByBuyNow())
                                <x-badge classes="bg-brand-50 text-brand-800 ring-brand-200">
                                    Bought outright
                                </x-badge>
                            @endif
                        </div>

                        <h2 class="mt-3 text-sm font-semibold text-slate-900 group-hover:text-brand-800">
                            {{ $auction->product->name }}
                        </h2>

                        <div class="mt-4 space-y-2 border-t border-slate-100 pt-3 text-sm">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-slate-500">Highest Bid (Credits)</span>
                                <span class="font-bold tabular-nums text-slate-900">
                                    {{ $auction->highest_bid_credits === null
                                        ? 'No bids'
                                        : number_format($auction->highest_bid_credits) }}
                                </span>
                            </div>

                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-slate-500">Buy Now</span>
                                <span class="font-semibold tabular-nums text-slate-700">
                                    <x-money :amount="$auction->product->buyNowPrice()" />
                                </span>
                            </div>

                            <div class="flex items-baseline justify-between gap-3">
                                <span class="text-slate-500">Winner pays</span>
                                <span class="font-semibold tabular-nums text-slate-700">
                                    <x-money :amount="$auction->settlementAmount()" />
                                </span>
                            </div>
                        </div>

                        @if ($auction->status->isOpen() && $auction->ends_at)
                            <p class="mt-3 text-xs text-slate-500">
                                Ends {{ $auction->ends_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                            </p>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-8">{{ $auctions->links() }}</div>
    @endif
</div>

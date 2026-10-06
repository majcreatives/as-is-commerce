@props(['auction', 'secondsRemaining' => null])

{{-- One auction in a grid.

     THE TWO FIGURES ARE NEVER MIXED. The Buy Now price is GH₵ and the highest
     bid is a count of credits, and they are rendered through different
     components so the markup itself keeps them apart. There is no line on this
     card that could be read as "this auction costs 180 cedis".

     No bidder is ever named. The card says what the figure to beat is, not whose
     it is. Its label is the auction's own: "Highest Bid" while a bid is what
     ranks, "Highest Total" when the running total is. --}}

@php
    $product = $auction->product;
    $highest = $auction->highest_bid_credits;
@endphp

<a href="{{ route('auctions.show', $auction) }}" wire:navigate
   {{ $attributes->merge([
       'class' => 'group flex flex-col rounded-xl border-[5px] border-white/25 bg-white shadow-sm transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
   ]) }}
   aria-label="{{ $product->name }} — {{ $auction->status->customerLabel() }}">

    <div class="relative flex aspect-4/3 items-center justify-center rounded-t-xl bg-slate-100">
        @if ($product->image())
            <img src="{{ $product->image() }}" alt="{{ $product->name }}"
                 class="h-full w-full rounded-t-xl object-cover" loading="lazy">
        @else
            <span class="text-xs font-medium text-slate-400">No image</span>
        @endif

        <span class="absolute left-3 top-3">
            <x-auction-status-badge :auction="$auction" />
        </span>

        @if ($secondsRemaining !== null && $auction->status->acceptsBids())
            <span class="absolute right-3 top-3 rounded-full bg-slate-900/80 px-2 py-1 text-xs font-semibold text-white">
                <x-countdown :seconds="$secondsRemaining" :ends-at="$auction->ends_at" />
            </span>
        @endif
    </div>

    {{-- p-3 on a phone, for the same reason as the product card: a two-column
     mobile grid leaves roughly 180px, which p-4 makes cramped. --}}
    <div class="flex flex-1 flex-col p-3 sm:p-4">
        <div class="flex flex-wrap items-center gap-1.5">
            <x-badge :classes="$product->condition->badgeClasses()">
                {{ $product->condition->label() }}
            </x-badge>

            @if ($auction->buyNowEnabled() && $auction->status->acceptsBuyNow())
                <x-badge classes="bg-emerald-50 text-emerald-800 ring-emerald-200">Buy Now available</x-badge>
            @endif
        </div>

        @if ($product->brand)
            <p class="mt-2 text-xs font-medium uppercase tracking-wide text-slate-500">
                {{ $product->brand->name }}
            </p>
        @endif

        <h3 class="mt-1 flex-1 text-sm font-semibold text-slate-900 group-hover:text-brand-800">
            {{ $product->name }}
        </h3>

        {{-- flex-wrap rather than a forced one-row flex: in a two-column phone
             grid these cards are around 180px wide, and a label like
             "Highest Bid (Credits)" beside its value has nowhere to go on a
             single line. Wrapping drops the value onto its own line rather than
             crushing both, and a card wide enough still shows them side by side. --}}
        <dl class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 text-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                {{-- The platform's own locked wording. Naming the unit in the
                     label is what stops the value beside it ever being read as
                     a price -- and "auction price" is a phrase that must never
                     appear anywhere. --}}
                <dt class="text-slate-600">{{ $auction->rules()->bidModel->leaderLabel() }}</dt>
                <dd class="font-semibold tabular-nums text-slate-900">
                    @if ($highest)
                        <x-credits :amount="$highest" />
                    @else
                        <span class="font-normal text-slate-500">No bids yet</span>
                    @endif
                </dd>
            </div>

            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                {{-- Cedis. A different quantity, rendered by a different
                     component, and never derived from the bid above. --}}
                <dt class="text-slate-600">Buy Now (GH₵)</dt>
                <dd class="font-semibold tabular-nums text-slate-900">
                    <x-money :amount="$product->buyNowPrice()" />
                </dd>
            </div>
        </dl>
    </div>
</a>

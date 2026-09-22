@props(['product', 'availability' => null])

{{-- One product in a grid.

     AVAILABILITY COMES FROM THE SERVER, NOT FROM STOCK ARITHMETIC HERE. A live
     auction reserves the unit it is selling, so a product with one unit and an
     auction running on it has zero *available* stock -- and a card that asked
     the catalog alone would print "Out of stock" on something anybody can bid
     for or buy outright this minute.

     `ListingAvailability` answers that question properly, from the auction's
     own state when an auction holds the unit and from the catalog otherwise.
     Passing nothing falls back to the catalog, which is correct for pages that
     have no auction context.

     NO STALE AUCTION FIGURES. Only a currently relevant auction reaches this,
     so a finished one can never leave a highest bid on a card. --}}

@php
    $hasAuction = $availability?->hasAuction() ?? false;
    $highest = $availability?->highestBidCredits();
    $obtainable = $availability?->obtainable ?? $product->isPurchasable();
@endphp

<a href="{{ route('products.show', $product->slug) }}" wire:navigate
   {{ $attributes->merge([
       'class' => 'group flex flex-col rounded-xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
   ]) }}
   aria-label="{{ $product->name }}">

    <div class="relative flex aspect-4/3 items-center justify-center rounded-t-xl bg-slate-100">
        @if ($product->image())
            <img src="{{ $product->image() }}" alt="{{ $product->name }}"
                 class="h-full w-full rounded-t-xl object-cover" loading="lazy">
        @else
            <span class="text-xs font-medium text-slate-400">No image</span>
        @endif

        @if ($hasAuction)
            <span class="absolute left-3 top-3">
                <x-auction-status-badge :auction="$availability->auction" />
            </span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        <div class="flex flex-wrap items-center gap-1.5">
            <x-badge :classes="$product->condition->badgeClasses()">
                {{ $product->condition->label() }}
            </x-badge>

            @unless ($obtainable)
                {{-- Said plainly, and without a purchase call to action
                     anywhere on the card. --}}
                <x-badge classes="bg-slate-100 text-slate-600 ring-slate-200">
                    Currently unavailable
                </x-badge>
            @endunless
        </div>

        @if ($product->brand)
            <p class="mt-2 text-xs font-medium uppercase tracking-wide text-slate-500">
                {{ $product->brand->name }}
            </p>
        @endif

        <h3 class="mt-1 flex-1 text-sm font-semibold text-slate-900 group-hover:text-brand-800">
            {{ $product->name }}
        </h3>

        <p class="mt-3 text-lg font-bold tabular-nums text-slate-900">
            <x-money :amount="$product->buyNowPrice()" />
        </p>

        @if ($hasAuction)
            <p class="mt-1 text-xs text-slate-600">
                @if ($highest)
                    {{-- The platform's own locked wording, and a count. Never
                         beside the cedis figure in a way that could read as
                         one price. --}}
                    {{ $availability->auction->rules()->bidModel->leaderLabel() }}: <span class="font-semibold"><x-credits :amount="$highest" /></span>
                @else
                    Auction open — no bids yet
                @endif
            </p>
        @endif
    </div>
</a>

{{-- The front page.

     Two paths, side by side, because that is what this platform is: buy it
     outright in cedis, or compete for it with credits. Nothing here promises
     savings, cheap products or a likely win — the copy describes the
     mechanism and lets somebody decide. --}}

<div>
    {{-- Hero --}}
    <section class="overflow-hidden rounded-2xl bg-brand-900 px-6 py-12 sm:px-10 sm:py-16">
        <div class="max-w-2xl">
            <span class="inline-flex items-center rounded-full bg-brand-800 px-3 py-1 text-xs font-semibold text-accent-300 ring-1 ring-inset ring-brand-700">
                Built for Ghana &middot; GH&#8373;
            </span>

            <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-5xl">
                Shop normally.<br class="hidden sm:block">
                Or compete for it with credits.
            </h1>

            <p class="mt-4 text-base text-brand-100 sm:text-lg">
                Every product has a Buy Now price in cedis. Some also run as auctions, where you
                bid with platform credits and the highest valid bid wins when the auction closes.
                Timing and results are decided on our servers, so everyone sees the same outcome.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                <x-button href="{{ route('products.index') }}" wire:navigate variant="accent" size="lg">
                    Shop products
                </x-button>
                <x-button href="{{ route('auctions.index') }}" wire:navigate variant="secondary" size="lg">
                    Explore auctions
                </x-button>
            </div>

            {{-- The disclosure a bidder most needs, on the front page rather
                 than only deep in a legal note. --}}
            <p class="mt-6 text-sm text-brand-200">
                Credits you bid are consumed straight away and are not returned if you do not win.
                <a href="{{ route('how-it-works') }}" wire:navigate class="font-semibold text-white underline">
                    How it works
                </a>
            </p>
        </div>
    </section>

    {{-- The two paths, stated plainly and separately, so neither figure can be
         read as the other. --}}
    <section class="mt-10 grid gap-4 md:grid-cols-2">
        <x-card>
            <h2 class="text-lg font-bold text-slate-900">Buy it outright</h2>
            <p class="mt-2 text-sm text-slate-600">
                Pay the product's Buy Now price in cedis and it is yours. No bidding, no waiting,
                no credits needed. Payment is verified with our provider before anything ships.
            </p>
            <x-button href="{{ route('products.index') }}" wire:navigate variant="secondary" size="sm" class="mt-4">
                Browse the shop
            </x-button>
        </x-card>

        <x-card>
            <h2 class="text-lg font-bold text-slate-900">Or bid with credits</h2>
            <p class="mt-2 text-sm text-slate-600">
                Buy credits, then commit as many as you like to an auction. The highest valid bid
                wins when it closes, and the winner pays a separate settlement amount in cedis.
                Credits are not cedis, and bid credits are consumed whether you win or lose.
            </p>
            <x-button href="{{ route('auctions.index') }}" wire:navigate variant="secondary" size="sm" class="mt-4">
                See live auctions
            </x-button>
        </x-card>
    </section>

    {{-- Live auctions. Real ones or none: an invented auction on a homepage is
         the same lie as an invented one anywhere else. --}}
    <section class="mt-12">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Closing soonest</h2>
                <p class="mt-1 text-sm text-slate-600">
                    @if ($openAuctionCount > 0)
                        {{ $openAuctionCount }} {{ Str::plural('auction', $openAuctionCount) }} open right now.
                    @else
                        Auctions open regularly.
                    @endif
                </p>
            </div>

            <a href="{{ route('auctions.index') }}" wire:navigate
               class="text-sm font-semibold text-brand-800 underline">All auctions</a>
        </div>

        @if ($liveAuctions->isEmpty())
            <div class="mt-4">
                <x-empty-state
                    title="No auctions running right now"
                    description="New auctions open regularly. Everything in the shop can be bought outright in the meantime." />
            </div>
        @else
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($liveAuctions as $auction)
                    <x-auction-card :auction="$auction"
                                    :seconds-remaining="$remaining[$auction->id] ?? null" />
                @endforeach
            </div>
        @endif
    </section>

    {{-- The catalog. --}}
    <section class="mt-12">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold tracking-tight text-slate-900">In the shop</h2>
                <p class="mt-1 text-sm text-slate-600">Everything here can be bought outright.</p>
            </div>

            <a href="{{ route('products.index') }}" wire:navigate
               class="text-sm font-semibold text-brand-800 underline">All products</a>
        </div>

        @if ($featured->isEmpty())
            <div class="mt-4">
                <x-empty-state
                    title="Nothing listed yet"
                    description="Products will appear here as soon as they are published." />
            </div>
        @else
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($featured as $product)
                    <x-product-card :product="$product"
                                    :availability="$availability[$product->id] ?? null" />
                @endforeach
            </div>
        @endif
    </section>

    {{-- Trust. Only claims the platform can actually back. --}}
    <section class="mt-12 grid gap-4 sm:grid-cols-3">
        <x-card>
            <h3 class="text-sm font-semibold text-slate-900">The server decides</h3>
            <p class="mt-1.5 text-sm text-slate-600">
                Auction timing, valid bids and winners are settled on our servers, never in your
                browser. A countdown on screen is there to inform you, not to decide anything.
            </p>
        </x-card>

        <x-card>
            <h3 class="text-sm font-semibold text-slate-900">Payments are verified</h3>
            <p class="mt-1.5 text-sm text-slate-600">
                Nothing is treated as paid because a page said so. Every payment is confirmed with
                our provider directly before an order moves.
            </p>
        </x-card>

        <x-card>
            <h3 class="text-sm font-semibold text-slate-900">Delivered by hand</h3>
            <p class="mt-1.5 text-sm text-slate-600">
                We pack and deliver orders ourselves, and record every step, so you can see where
                yours has got to.
            </p>
        </x-card>
    </section>
</div>

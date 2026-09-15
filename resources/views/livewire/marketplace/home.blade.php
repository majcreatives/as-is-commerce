{{-- The front page.

     A shop first, with a gamified auction channel layered over it. The store
     is the identity and the primary commerce; auctions are an experience some
     products carry. Where a product is being auctioned right now, the auction
     appears on that product's own card -- it does not get a banner of its own
     above the shop. What is shown here is read from the catalog and the
     auction engine, and nothing is promised: the copy describes the mechanism
     and lets somebody decide. --}}

<div>
    {{-- Hero --}}
    <section class="overflow-hidden rounded-2xl bg-brand-900 px-6 py-12 sm:px-10 sm:py-16">
        <div class="max-w-2xl">
            <span class="inline-flex items-center rounded-full bg-brand-800 px-3 py-1 text-xs font-semibold text-accent-300 ring-1 ring-inset ring-brand-700">
                Built for Ghana &middot; GH&#8373;
            </span>

            <h1 class="mt-4 text-3xl font-bold tracking-tight text-white sm:text-5xl">
                The shop, first.<br class="hidden sm:block">
                Some products are also auctioned.
            </h1>

            <p class="mt-4 text-base text-brand-100 sm:text-lg">
                This is a store: every product has a Buy Now price in cedis, and most are yours
                the moment you pay. Some products also run as auctions, where you bid with
                platform credits and the highest valid bid wins when the auction closes.
                Timing and results are decided on our servers, so everyone sees the same outcome.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                <x-button href="{{ route('products.index') }}" wire:navigate variant="accent" size="lg">
                    Shop now
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

    {{-- The shop, primary. An auction running on one of these products is
         shown on that product's card, not as a banner above the store. --}}
    <section class="mt-12">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold tracking-tight text-slate-900">In the shop</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Everything here can be bought outright; a live auction on a product is
                    shown on its card.
                </p>
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

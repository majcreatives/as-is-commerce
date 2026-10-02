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

            {{-- Two sentences, not a lesson. The full explanation lives on How
                 It Works, linked below; a front page that teaches the whole
                 model before somebody has looked at a product is asking them to
                 read before they can shop. --}}
            <p class="mt-4 text-base text-brand-100 sm:text-lg">
                Every product has a Buy Now price in cedis, and most are yours the moment you pay.
                Some are also auctioned: you bid with credits, and the largest total of credits committed wins.
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
                <h2 class="text-xl font-bold tracking-tight text-slate-900">Newly added</h2>
                <p class="mt-1 text-sm text-slate-600">
                    The latest to the catalog, all buyable outright; a live auction on a product is
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
            <x-scroll-row label="Newly added products">
                @foreach ($featured as $product)
                    <x-product-card :product="$product"
                                    :availability="$availability[$product->id] ?? null" />
                @endforeach
            </x-scroll-row>
        @endif
    </section>

    {{-- What people have actually done with these products lately: units on
         paid orders and accepted bids, read from the ledger, never invented.
         No promise about what the activity means -- it is a signal that the
         item is being bought or bid on, nothing more. --}}
    <section class="mt-12">
        <div>
            <h2 class="text-xl font-bold tracking-tight text-slate-900">Trending</h2>
            <p class="mt-1 text-sm text-slate-600">
                What other shoppers have bought or bid on lately.
            </p>
        </div>

        @if ($trending->isEmpty())
            <div class="mt-4">
                <x-empty-state
                    title="Not enough activity yet"
                    description="Trending fills in as people start buying and bidding." />
            </div>
        @else
            <x-scroll-row label="Trending products">
                @foreach ($trending as $product)
                    <x-product-card :product="$product"
                                    :availability="$availability[$product->id] ?? null" />
                @endforeach
            </x-scroll-row>
        @endif
    </section>

    {{-- What customers have said. Only published, featured stories reach here,
         and only three. The section disappears entirely when nothing qualifies:
         a "Success stories" heading over nothing reads as an absence of
         customers rather than an absence of content, which is not the same
         claim and is not one we get to make. --}}
    @if ($stories->isNotEmpty())
        <section class="mt-12">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-slate-900">Success stories</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        In their own words.
                    </p>
                </div>

                <a href="{{ route('success-stories.index') }}" wire:navigate
                   class="text-sm font-semibold text-brand-800 underline">All stories</a>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($stories as $story)
                    <x-success-story-card :story="$story" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- Who we work with. Hidden for the same reason as the stories above: an
         empty strip of partner logos would imply there are no partners, which
         is a statement about the business rather than about this page. --}}
    @if ($partners->isNotEmpty())
        <section class="mt-12">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-slate-900">Partners</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Businesses that work with us.
                    </p>
                </div>

                <a href="{{ route('partners.index') }}" wire:navigate
                   class="text-sm font-semibold text-brand-800 underline">All partners</a>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($partners as $partner)
                    <x-partner-card :partner="$partner" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- The three trust cards that sat here explained how the platform works
         rather than giving a reason to shop, and were repeated in full on the
         How It Works page. They now live only there ("What you can rely on"),
         so the front page is the shop and the explanation has one home. --}}
</div>

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
        <div class="grid grid-cols-1 items-center gap-8 lg:grid-cols-12 lg:gap-12">
            <div class="flex flex-col items-start gap-5 lg:col-span-6">
                <h1 class="text-3xl font-bold tracking-tight text-white sm:text-5xl">
                    Shop smart<br class="hidden sm:block">
                    Find your deal.
                </h1>

                {{-- Two sentences, not a lesson. It says that both ways of
                     buying exist and leaves the choice to the reader; the full
                     explanation lives on How It Works, linked below. --}}
                <p class="text-base text-brand-100 sm:text-lg">
                    Buy what you want instantly &mdash; or compete for selected products in live auctions. From everyday shopping to the thrill of an auction, you decide how you want to get the deal.
                </p>

                <div class="flex flex-wrap items-center gap-4">
                    <x-button href="{{ route('products.index') }}" wire:navigate variant="accent" size="lg">
                        Shop now
                    </x-button>
                    <x-button href="{{ route('auctions.index') }}" wire:navigate variant="secondary" size="lg">
                        Explore auctions
                    </x-button>
                </div>

                {{-- The disclosure a bidder most needs, on the front page rather
                     than only deep in a legal note. --}}
                <p class="text-sm text-brand-200">
                    Credits you bid are consumed straight away and are not returned if you do not win.
                    <a href="{{ route('how-it-works') }}" wire:navigate class="font-semibold text-white underline">
                        How it works
                    </a>
                </p>
            </div>

            @php
                // What the collage is made of, decided in the component: the real
                // products this page has already read, and only those that have a
                // picture. No stock image and no example product ever appears here
                // -- a fabricated picture on the front page is the same lie as a
                // fabricated product.
                //
                // Three columns, each a vertical run of cards with its own top
                // offset and its own proportions. Column-major, which is what makes
                // it stagger instead of forming a grid.
                $galleryColumns = [
                    ['offset' => 'pt-8', 'ratios' => ['aspect-3/4', 'aspect-square', 'aspect-3/4']],
                    ['offset' => '', 'ratios' => ['aspect-3/5', 'aspect-4/3', 'aspect-square']],
                    ['offset' => 'pt-12', 'ratios' => ['aspect-3/4', 'aspect-3/5', 'aspect-square']],
                ];

                $galleryCards = $gallery->values()->map(
                    fn (\App\Models\Product $product, int $place): array => [
                        'product' => $product,
                        // The first card of each column sits near the top of the
                        // collage at every width, so those are worth having early.
                        // The rest wait for a reader who gets that far.
                        'eager' => $place % 3 === 0,
                    ],
                );
            @endphp

            @if ($galleryCards->isNotEmpty())
                {{-- Decorative, so it is hidden from assistive technology: every
                     product pictured here is presented properly, with its name and
                     price, in the rows below. Announcing it twice is only noise. --}}
                <div class="lg:col-span-6">
                    <div class="grid h-full min-h-[480px] grid-cols-3 gap-3 sm:gap-4" aria-hidden="true">
                        @foreach ($galleryCards->chunk(3) as $column => $cards)
                            @php
                                $spec = $galleryColumns[$column] ?? ['offset' => '', 'ratios' => []];
                            @endphp
                            <div class="{{ trim('flex flex-col gap-3 sm:gap-4 '.$spec['offset']) }}">
                                @foreach ($cards as $card)
                                    <div class="{{ $spec['ratios'][$loop->index] ?? 'aspect-square' }} overflow-hidden rounded-xl shadow-md">
                                        <img src="{{ $card['product']->image() }}"
                                             class="h-full w-full object-cover"
                                             alt=""
                                             @if ($card['eager'])
                                                 loading="eager" fetchpriority="high"
                                             @else
                                                 loading="lazy" decoding="async"
                                             @endif
                                        >
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
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

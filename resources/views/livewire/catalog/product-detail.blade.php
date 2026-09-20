<div>
    {{-- Breadcrumb --}}
    <nav class="mb-4 flex flex-wrap items-center gap-1 text-sm text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('products.index') }}" wire:navigate class="hover:text-slate-900">Products</a>

        @foreach ($product->category->ancestry() as $crumb)
            <span aria-hidden="true">/</span>
            <a href="{{ route('products.index', ['category' => $crumb->slug]) }}" wire:navigate
               class="hover:text-slate-900">{{ $crumb->name }}</a>
        @endforeach
    </nav>

    <div class="grid gap-8 lg:grid-cols-2">

        {{-- Images --}}
        @php
            // The gallery, featured first. Before any gallery exists, the
            // product's own single-column image is still what a listing shows.
            $gallery = $product->images->map(fn ($image) => $image->url())->all();

            if ($gallery === []) {
                $legacy = $product->image_path;
                if (is_string($legacy) && $legacy !== '') {
                    $gallery[] = $legacy;
                }
            }
        @endphp

        @php($imageCount = count($gallery))

        <div x-data="productGallery({{ $imageCount }})">
            <div class="relative flex aspect-4/3 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                @if ($gallery !== [])
                    {{-- The featured image is a button so the large view is
                         reachable by keyboard and announces itself, rather
                         than being a click target only a mouse can find.
                         Without Alpine it is an inert button around a picture,
                         which is exactly what the page showed before. --}}
                    <button type="button" x-on:click="openViewer()"
                            class="absolute inset-0 h-full w-full cursor-zoom-in focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                            aria-label="View larger image of {{ $product->name }}">
                        @foreach ($gallery as $index => $src)
                            <img @if ($index !== 0) x-show="active === {{ $index }}" x-cloak @else x-show="active === 0" @endif
                                 src="{{ $src }}"
                                 alt="{{ $product->name }} — image {{ $index + 1 }} of {{ $imageCount }}"
                                 class="absolute inset-0 h-full w-full rounded-xl object-cover">
                        @endforeach
                    </button>

                    @if ($imageCount > 1)
                        <span class="pointer-events-none absolute bottom-3 right-3 rounded-full bg-slate-900/70 px-2.5 py-1 text-xs font-semibold text-white">
                            <span x-text="active + 1">1</span> / {{ $imageCount }}
                        </span>
                    @endif
                @else
                    <span class="text-sm font-medium text-slate-400">No image available</span>
                @endif
            </div>

            @if ($imageCount > 1)
                <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="Product images">
                    @foreach ($gallery as $index => $src)
                        <button type="button" x-on:click="show({{ $index }})"
                                aria-label="Show image {{ $index + 1 }} of {{ $imageCount }}"
                                :aria-current="active === {{ $index }}"
                                :class="active === {{ $index }} ? 'ring-2 ring-brand-600 ring-offset-2' : 'opacity-70 hover:opacity-100'"
                                class="h-16 w-16 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                            <img src="{{ $src }}" alt="" class="h-full w-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- ------------------------------------------- The large view --}}
            @if ($gallery !== [])
                <div x-show="open" x-cloak x-ref="dialog"
                     x-on:keydown.escape.window="closeViewer()"
                     x-on:keydown.left.window="open && previous()"
                     x-on:keydown.right.window="open && next()"
                     x-on:keydown.tab="trapTab($event)"
                     role="dialog" aria-modal="true"
                     aria-label="{{ $product->name }} images"
                     class="fixed inset-0 z-50 flex flex-col bg-slate-900/95 p-4 sm:p-6">

                    <div class="flex items-center justify-between gap-4 text-white">
                        {{-- Announced politely, so a screen reader says which
                             image is showing without interrupting. --}}
                        <p class="text-sm font-medium" aria-live="polite">
                            @if ($imageCount > 1)
                                Image <span x-text="active + 1">1</span> of {{ $imageCount }}
                            @else
                                {{ $product->name }}
                            @endif
                        </p>

                        <button type="button" x-ref="closeButton" x-on:click="closeViewer()"
                                class="rounded-full p-2 transition hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                aria-label="Close image viewer">
                            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="relative flex min-h-0 flex-1 items-center justify-center"
                         x-on:touchstart.passive="onTouchStart($event)"
                         x-on:touchend.passive="onTouchEnd($event)">
                        @foreach ($gallery as $index => $src)
                            <img x-show="active === {{ $index }}" @if ($index !== 0) x-cloak @endif
                                 src="{{ $src }}"
                                 alt="{{ $product->name }} — image {{ $index + 1 }} of {{ $imageCount }}"
                                 class="max-h-full max-w-full object-contain">
                        @endforeach

                        @if ($imageCount > 1)
                            <button type="button" x-on:click="previous()"
                                    class="absolute left-0 rounded-full bg-white/10 p-3 text-white transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                    aria-label="Previous image">
                                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                                </svg>
                            </button>

                            <button type="button" x-on:click="next()"
                                    class="absolute right-0 rounded-full bg-white/10 p-3 text-white transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                                    aria-label="Next image">
                                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                </svg>
                            </button>
                        @endif
                    </div>

                    @if ($imageCount > 1)
                        <div class="mt-4 flex justify-center gap-2 overflow-x-auto" role="group" aria-label="Product images">
                            @foreach ($gallery as $index => $src)
                                <button type="button" x-on:click="show({{ $index }})"
                                        aria-label="Show image {{ $index + 1 }} of {{ $imageCount }}"
                                        :aria-current="active === {{ $index }}"
                                        :class="active === {{ $index }} ? 'ring-2 ring-white' : 'opacity-60 hover:opacity-100'"
                                        class="h-14 w-14 shrink-0 overflow-hidden rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                                    <img src="{{ $src }}" alt="" class="h-full w-full object-cover">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- Detail --}}
        <div>
            {{-- Real navigation, not decorative tags. Each one leads to the
                 catalogue filtered by that value, which the catalogue already
                 reads from the query string. --}}
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('products.index', ['condition' => $product->condition->value]) }}" wire:navigate
                   class="rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                    <x-badge :classes="$product->condition->badgeClasses()">
                        {{ $product->condition->label() }}
                    </x-badge>
                </a>

                @if ($product->brand)
                    <a href="{{ route('products.index', ['brand' => $product->brand->slug]) }}" wire:navigate
                       class="rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        <x-badge classes="bg-slate-100 text-slate-700 ring-slate-200 hover:bg-slate-200">
                            {{ $product->brand->name }}
                        </x-badge>
                    </a>
                @endif
            </div>

            <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                {{ $product->name }}
            </h1>

            @if ($product->short_description)
                <p class="mt-2 text-slate-600">{{ $product->short_description }}</p>
            @endif

            {{-- ------------------------------------------- The auction, first

                 When an auction holds this unit, the auction is what a customer
                 can act on: it owns the Buy Now path for the unit as well as
                 the bidding, so it leads and the outright price follows it.

                 Only a currently relevant auction ever reaches this page, so no
                 finished auction can leave a stale figure here. The bid is a
                 COUNT of credits -- never money, never a currency symbol, and
                 never called an auction price. --}}
            @if ($availability->hasAuction())
                @php($liveAuction = $availability->auction)

                <div class="mt-6 rounded-xl border-2 border-accent-300 bg-accent-50/60 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <x-auction-status-badge :auction="$liveAuction" />

                        @if ($secondsRemaining !== null)
                            <p class="text-sm font-medium text-slate-700">
                                {{-- Informational. The auction ends when its own
                                     end time says so and the sweep notices; this
                                     number announces no outcome. --}}
                                Closes in
                                <x-countdown :seconds="$secondsRemaining" :ends-at="$liveAuction->ends_at"
                                             class="font-bold tabular-nums" />
                            </p>
                        @elseif ($liveAuction->scheduled_start_at)
                            <p class="text-sm font-medium text-slate-700">
                                Opens
                                {{ $liveAuction->scheduled_start_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                            </p>
                        @endif
                    </div>

                    <div class="mt-4">
                        <p class="text-sm font-medium text-slate-600">Highest Bid (Credits)</p>

                        @if ($availability->highestBidCredits() !== null)
                            <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                                <x-credits :amount="$availability->highestBidCredits()" />
                            </p>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ number_format($liveAuction->bid_count) }}
                                {{ Str::plural('bid', $liveAuction->bid_count) }} placed.
                            </p>
                        @else
                            {{-- No bids is an honest state, not a zero. --}}
                            <p class="mt-1 text-lg font-semibold text-slate-700">No bids yet</p>
                        @endif
                    </div>

                    <div class="mt-5">
                        <x-button href="{{ route('auctions.show', $liveAuction) }}" wire:navigate
                                  variant="accent" size="lg">
                            {{ $availability->canBid ? 'Bid on this product' : 'View the auction' }}
                        </x-button>
                    </div>

                    <p class="mt-3 text-xs text-slate-600">
                        Bidding costs credits, which are consumed straight away and are not returned
                        if you do not win.
                        <a href="{{ route('how-it-works') }}" wire:navigate class="font-semibold underline">How it works</a>
                    </p>
                </div>
            @endif

            {{-- Price. The product's own Buy Now price, in GH₵. Deliberately
                 not described as an auction price, and not shown alongside any
                 credit figure. --}}
            <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
                <p class="text-sm font-medium text-slate-500">Buy Now price</p>
                <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                    {{ settings()->getString('currency_symbol', 'GH₵') }} {{ $product->buyNowPrice()->format() }}
                </p>

                <div class="mt-4 border-t border-slate-100 pt-4">
                    {{-- Availability from authoritative state. A live auction
                         reserves the unit it is selling, so asking the catalog
                         alone would call an auctioned product out of stock. --}}
                    @if ($availability->hasAuction())
                        <p class="text-sm font-medium text-slate-700">
                            This product is in an auction right now.
                        </p>
                    @elseif ($availability->obtainable)
                        <p class="text-sm font-medium text-emerald-700">
                            In stock &middot; {{ number_format($product->availableStock()) }} available
                        </p>
                    @else
                        <p class="text-sm font-medium text-slate-600">Currently unavailable</p>
                    @endif
                </div>

                @error('cart')
                    <x-alert variant="danger" class="mt-4" role="alert">{{ $message }}</x-alert>
                @enderror

                <div class="mt-4 flex flex-wrap gap-3">
                    @if ($availability->hasAuction())
                        {{-- The auction owns the Buy Now path for this unit:
                             its price carries the bidder's credit discount, and
                             completing it ends the auction. --}}
                        <x-button href="{{ route('auctions.show', $availability->auction) }}"
                                  wire:navigate variant="secondary">
                            Buy it outright on the auction page
                        </x-button>
                    @elseif ($availability->canBuyNow)
                        @auth
                            <div class="flex flex-wrap items-end gap-3">
                                <x-field label="Quantity" name="quantity" class="w-28">
                                    <input type="number" id="quantity" min="1"
                                           max="{{ $product->availableStock() }}"
                                           wire:model="quantity"
                                           class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                </x-field>

                                <x-button wire:click="addToCart" wire:loading.attr="disabled" wire:target="addToCart">
                                    <span wire:loading.remove wire:target="addToCart">Add to cart</span>
                                    <span wire:loading wire:target="addToCart">Adding…</span>
                                </x-button>
                            </div>

                            <p class="mt-3 text-xs text-slate-500">
                                Adding to your cart keeps it aside for you; it is reserved only when
                                you place the order.
                            </p>
                        @else
                            <x-button href="{{ route('login') }}" wire:navigate>Sign in to buy</x-button>
                        @endauth
                    @else
                        {{-- No purchase control at all on something that cannot
                             be bought. A button that always fails is worse than
                             none. --}}
                        <p class="text-sm text-slate-600">
                            This product is not available to buy right now.
                        </p>
                    @endif
                </div>

                @if ($availability->hasAuction())
                    <p class="mt-3 text-xs text-slate-500">
                        Bid with credits, or buy it outright from the auction page. Completing a
                        Buy Now ends the auction.
                    </p>
                @endif
            </div>

            {{-- Each value is a link into the catalogue filtered by it, so a
                 customer who likes the brand or wants everything refurbished
                 can follow it rather than going back and setting a filter by
                 hand. Only the SKU is inert: it identifies one product, so a
                 listing of it would be a listing of one. --}}
            @php($metadataLink = 'font-medium text-brand-800 underline decoration-brand-300 underline-offset-2 hover:decoration-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600')

            <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">SKU</dt>
                    <dd class="font-mono text-slate-900">{{ $product->sku }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Condition</dt>
                    <dd>
                        <a href="{{ route('products.index', ['condition' => $product->condition->value]) }}"
                           wire:navigate class="{{ $metadataLink }}">
                            {{ $product->condition->label() }}
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Category</dt>
                    <dd>
                        <a href="{{ route('products.index', ['category' => $product->category->slug]) }}"
                           wire:navigate class="{{ $metadataLink }}">
                            {{ $product->category->name }}
                        </a>
                    </dd>
                </div>
                @if ($product->brand)
                    <div>
                        <dt class="text-slate-500">Brand</dt>
                        <dd>
                            <a href="{{ route('products.index', ['brand' => $product->brand->slug]) }}"
                               wire:navigate class="{{ $metadataLink }}">
                                {{ $product->brand->name }}
                            </a>
                        </dd>
                    </div>
                @endif
            </dl>

            <p class="mt-3 text-xs text-slate-500">{{ $product->condition->description() }}</p>
        </div>
    </div>

    @if ($product->description)
        <x-card title="Description" class="mt-8">
            <p class="whitespace-pre-line text-sm leading-relaxed text-slate-700">{{ $product->description }}</p>
        </x-card>
    @endif

    @if ($related->isNotEmpty())
        <section class="mt-10">
            <h2 class="mb-4 text-lg font-semibold text-slate-900">More in {{ $product->category->name }}</h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($related as $other)
                    <x-product-card :product="$other" />
                @endforeach
            </div>
        </section>
    @endif
</div>

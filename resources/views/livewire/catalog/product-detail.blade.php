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

        <div x-data="{ active: 0 }">
            <div class="relative flex aspect-4/3 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                @if ($gallery !== [])
                    @foreach ($gallery as $index => $src)
                        {{-- The featured image renders without x-cloak, so the
                             page shows a picture even before Alpine runs; the
                             others stay hidden until the active one changes. --}}
                        <img x-show="active === {{ $index }}"
                             @if ($index !== 0) x-cloak @endif
                             src="{{ $src }}"
                             alt="{{ $product->name }} image {{ $index + 1 }}"
                             class="absolute inset-0 h-full w-full rounded-xl object-cover">
                    @endforeach
                @else
                    <span class="text-sm font-medium text-slate-400">No image available</span>
                @endif
            </div>

            @if (count($gallery) > 1)
                <div class="mt-3 flex gap-2" role="group" aria-label="Product images">
                    @foreach ($gallery as $index => $src)
                        <button type="button" @click="active = {{ $index }}"
                                :aria-label="'Show image ' + ({{ $index }} + 1)"
                                :class="active === {{ $index }} ? 'ring-2 ring-brand-600 ring-offset-2' : 'opacity-70 hover:opacity-100'"
                                class="h-16 w-16 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-100">
                            <img src="{{ $src }}" alt="{{ $product->name }} image {{ $index + 1 }}"
                                 class="h-full w-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Detail --}}
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <x-badge :classes="$product->condition->badgeClasses()">
                    {{ $product->condition->label() }}
                </x-badge>

                @if ($product->brand)
                    <x-badge classes="bg-slate-100 text-slate-700 ring-slate-200">{{ $product->brand->name }}</x-badge>
                @endif
            </div>

            <h1 class="mt-3 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                {{ $product->name }}
            </h1>

            @if ($product->short_description)
                <p class="mt-2 text-slate-600">{{ $product->short_description }}</p>
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
                        <x-button href="{{ route('auctions.show', $availability->auction) }}" wire:navigate>
                            View the auction
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

            <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-500">SKU</dt>
                    <dd class="font-mono text-slate-900">{{ $product->sku }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Condition</dt>
                    <dd class="text-slate-900">{{ $product->condition->label() }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Category</dt>
                    <dd class="text-slate-900">{{ $product->category->name }}</dd>
                </div>
                @if ($product->brand)
                    <div>
                        <dt class="text-slate-500">Brand</dt>
                        <dd class="text-slate-900">{{ $product->brand->name }}</dd>
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

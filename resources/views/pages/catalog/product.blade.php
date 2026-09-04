<x-layouts.app :title="$product->name">
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

        {{-- Image --}}
        <div class="flex aspect-4/3 items-center justify-center rounded-xl border border-slate-200 bg-slate-100">
            @if ($product->image_path)
                <img src="{{ $product->image_path }}" alt="{{ $product->name }}"
                     class="h-full w-full rounded-xl object-cover">
            @else
                <span class="text-sm font-medium text-slate-400">No image available</span>
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
                    @if ($product->isInStock())
                        <p class="text-sm font-medium text-emerald-700">
                            In stock &middot; {{ number_format($product->availableStock()) }} available
                        </p>
                    @else
                        <p class="text-sm font-medium text-slate-600">Currently out of stock</p>
                    @endif
                </div>

                {{-- No Buy Now button: checkout does not exist yet, and a
                     control that does nothing is worse than none at all. --}}
                <p class="mt-4 text-xs text-slate-500">
                    Online checkout is not open yet.
                </p>
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
</x-layouts.app>

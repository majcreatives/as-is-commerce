@props(['product'])

{{-- One product in a grid. Shows the product's own Buy Now price in GHS.
     No credit figure appears here, and no auction figure either -- neither
     exists yet, and inventing one would be worse than showing nothing. --}}
<a href="{{ route('products.show', $product->slug) }}" wire:navigate
   {{ $attributes->merge([
       'class' => 'group flex flex-col rounded-xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2',
   ]) }}>

    <div class="flex aspect-4/3 items-center justify-center rounded-t-xl bg-slate-100">
        @if ($product->image_path)
            <img src="{{ $product->image_path }}" alt="{{ $product->name }}"
                 class="h-full w-full rounded-t-xl object-cover" loading="lazy">
        @else
            <span class="text-xs font-medium text-slate-400">No image</span>
        @endif
    </div>

    <div class="flex flex-1 flex-col p-4">
        <div class="flex flex-wrap items-center gap-1.5">
            <x-badge :classes="$product->condition->badgeClasses()">
                {{ $product->condition->label() }}
            </x-badge>

            @unless ($product->isInStock())
                <x-badge classes="bg-slate-100 text-slate-600 ring-slate-200">Out of stock</x-badge>
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
            {{ settings()->getString('currency_symbol', 'GH₵') }} {{ $product->buyNowPrice()->format() }}
        </p>
    </div>
</a>

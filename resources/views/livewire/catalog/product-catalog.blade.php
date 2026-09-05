<div>
    <x-page-header
        title="Products"
        description="Everything currently in our catalog, with its Buy Now price in GH₵." />

    <div class="grid gap-6 lg:grid-cols-4">

        {{-- Filters --}}
        <aside class="lg:col-span-1">
            <x-card>
                <div class="space-y-5">
                    <x-field label="Search" name="search">
                        <x-input id="search" type="search" wire:model.live.debounce.300ms="search"
                                 placeholder="Name or SKU" />
                    </x-field>

                    <x-field label="Category" name="category">
                        <select id="category" wire:model.live="category"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            <option value="">All categories</option>
                            @foreach ($categories as $option)
                                <option value="{{ $option->slug }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Brand" name="brand">
                        <select id="brand" wire:model.live="brand"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            <option value="">All brands</option>
                            @foreach ($brands as $option)
                                <option value="{{ $option->slug }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Condition" name="condition">
                        <select id="condition" wire:model.live="condition"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            <option value="">Any condition</option>
                            @foreach ($conditions as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model.live="availableOnly"
                               class="size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                        {{-- "Available" means obtainable, which includes a
                             product whose unit a live auction is holding.
                             Available stock alone would hide every auctioned
                             item. --}}
                        Available now
                    </label>

                    @if ($this->hasFilters())
                        <x-button wire:click="clearFilters" variant="secondary" size="sm" class="w-full">
                            Clear filters
                        </x-button>
                    @endif
                </div>
            </x-card>
        </aside>

        {{-- Results --}}
        <div class="lg:col-span-3">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-600">
                    {{ $products->total() }}
                    {{ Str::plural('product', $products->total()) }}
                </p>

                <div class="flex items-center gap-2">
                    <label for="sort" class="text-sm text-slate-600">Sort</label>
                    <select id="sort" wire:model.live="sort"
                            class="rounded-lg border-0 bg-white py-2 pl-3 pr-8 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600">
                        @foreach ($sorts as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($products->isEmpty())
                <x-empty-state
                    title="No products match"
                    description="Try a different search term, or clear the filters.">
                    @if ($this->hasFilters())
                        <x-button wire:click="clearFilters" variant="primary" size="sm">Clear filters</x-button>
                    @endif
                </x-empty-state>
            @else
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($products as $product)
                        <x-product-card :product="$product" :availability="$availability[$product->id] ?? null" />
                    @endforeach
                </div>

                @if ($products->hasPages())
                    <div class="mt-6">{{ $products->links() }}</div>
                @endif
            @endif
        </div>
    </div>
</div>

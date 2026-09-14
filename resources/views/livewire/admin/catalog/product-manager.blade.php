<x-admin.shell>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            title="Products"
            description="The platform's own catalog. Stock is managed separately, under Inventory, because every change to it has to be a recorded movement." />

        @can('products.create')
            <x-button wire:click="create" variant="primary" class="shrink-0">New product</x-button>
        @endcan
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    @error('lifecycle')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    @if ($showForm)
        <x-card :title="$editingId ? 'Edit product' : 'New product'" class="mb-6">
            <form wire:submit="save" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field label="Name" name="name" :error="$errors->first('name')">
                        <x-input id="name" wire:model="name" :error="$errors->has('name')" required />
                    </x-field>

                    <x-field label="SKU" name="sku" :error="$errors->first('sku')"
                             hint="The business identifier used for stock work. Leave blank to generate one.">
                        <x-input id="sku" wire:model="sku" :error="$errors->has('sku')" />
                    </x-field>

                    <x-field label="Category" name="category_id" :error="$errors->first('category_id')">
                        <select id="category_id" wire:model="category_id"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Brand" name="brand_id" :error="$errors->first('brand_id')" optional
                             hint="Not everything has a conventional brand.">
                        <select id="brand_id" wire:model="brand_id"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            <option value="">No brand</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Condition" name="condition" :error="$errors->first('condition')">
                        <select id="condition" wire:model="condition"
                                class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                            @foreach ($conditions as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </x-field>

                    <x-field label="Buy Now price" name="price" :error="$errors->first('price')"
                             hint="In cedis, for example 5500.00. This is the product's own price and has no relationship to credits.">
                        <x-input id="price" inputmode="decimal" placeholder="5500.00" wire:model="price"
                                 :error="$errors->has('price')" required />
                    </x-field>

                    <div class="sm:col-span-2">
                        <x-field label="Slug" name="slug" :error="$errors->first('slug')" optional
                                 hint="Used in the product URL. Generated from the name if left blank.">
                            <x-input id="slug" wire:model="slug" :error="$errors->has('slug')" />
                        </x-field>
                    </div>

                    <div class="sm:col-span-2">
                        <x-field label="Short description" name="short_description"
                                 :error="$errors->first('short_description')" optional>
                            <x-input id="short_description" wire:model="short_description"
                                     :error="$errors->has('short_description')" />
                        </x-field>
                    </div>

                    <div class="sm:col-span-2">
                        <x-field label="Description" name="description" :error="$errors->first('description')" optional>
                            <textarea id="description" wire:model="description" rows="5"
                                      class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm"></textarea>
                        </x-field>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <x-button type="button" wire:click="$set('showForm', false)" variant="secondary">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="disabled">Save product</x-button>
                </div>

                @unless ($editingId)
                    <p class="text-right text-xs text-slate-500">
                        New products are created as drafts and are not publicly visible until activated.
                    </p>
                @endunless
            </form>
        </x-card>
    @endif

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
        {{-- A placeholder is not a label: it disappears the moment somebody
             types, and a screen reader may never announce it. --}}
        <x-input type="search" wire:model.live.debounce.300ms="search"
                 aria-label="Search products by name or SKU"
                 placeholder="Search by name or SKU" class="max-w-md" />

        <select wire:model.live="status"
                class="rounded-lg border-0 bg-white py-2 pl-3 pr-8 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600">
            <option value="">All statuses</option>
            @foreach ($statuses as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[60rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">SKU</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Product</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Category</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Condition</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Buy Now</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Available</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($products as $product)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $product->sku }}</td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $product->name }}</span>
                                @if ($product->brand)
                                    <span class="block text-xs text-slate-500">{{ $product->brand->name }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $product->category->name }}</td>
                            <td class="px-4 py-3">
                                <x-badge :classes="$product->condition->badgeClasses()">
                                    {{ $product->condition->label() }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-900">
                                {{ $product->currency }} {{ $product->buyNowPrice()->format() }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <span class="font-semibold text-slate-900">{{ number_format($product->availableStock()) }}</span>
                                @if ($product->stock_reserved > 0)
                                    <span class="block text-xs text-slate-400">
                                        {{ number_format($product->stock_reserved) }} reserved
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :classes="$product->status->badgeClasses()">
                                    {{ $product->status->label() }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    @can('products.update')
                                        @if ($product->status->isEditable())
                                            <x-button wire:click="edit({{ $product->id }})"
                                                      variant="secondary" size="sm">Edit</x-button>
                                        @endif
                                    @endcan

                                    @can('products.activate')
                                        @if ($product->status->canTransitionTo(\App\Enums\ProductStatus::Active))
                                            <x-button wire:click="changeStatus({{ $product->id }}, 'active')"
                                                      variant="primary" size="sm">Activate</x-button>
                                        @endif

                                        @if ($product->status->canTransitionTo(\App\Enums\ProductStatus::Inactive))
                                            <x-button wire:click="changeStatus({{ $product->id }}, 'inactive')"
                                                      variant="ghost" size="sm">Deactivate</x-button>
                                        @endif
                                    @endcan

                                    @can('products.archive')
                                        @if ($product->status->canTransitionTo(\App\Enums\ProductStatus::Archived))
                                            <x-button wire:click="changeStatus({{ $product->id }}, 'archived')"
                                                      wire:confirm="Archive {{ $product->name }}? This cannot be undone."
                                                      variant="ghost" size="sm">Archive</x-button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10">
                                <x-empty-state
                                    title="No products match"
                                    description="Create a product to start building the catalog." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($products->hasPages())
        <div class="mt-4">{{ $products->links() }}</div>
    @endif
</x-admin.shell>

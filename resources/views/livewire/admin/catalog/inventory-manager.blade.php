<div>
    <x-admin.nav />

    <x-page-header
        title="Inventory"
        description="Stock levels and every movement behind them. There is no way to overwrite a stock figure — it changes only by posting a movement with a reason." />

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    <div class="mb-4">
        {{-- A placeholder is not a label: it disappears the moment somebody
             types, and a screen reader may never announce it. --}}
        <x-input type="search" wire:model.live.debounce.300ms="search"
                 aria-label="Search inventory by product name or SKU"
                 placeholder="Search by name or SKU" class="max-w-md" />
    </div>

    {{-- Stock levels --}}
    <x-card :padded="false" class="mb-8">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">SKU</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Product</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">On hand</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Reserved</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Available</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($products as $product)
                        <tr>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $product->sku }}</td>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $product->name }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ number_format($product->stock_on_hand) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ number_format($product->stock_reserved) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                {{ number_format($product->availableStock()) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @can('inventory.adjust')
                                    <x-button wire:click="startAdjustment({{ $product->id }})"
                                              variant="secondary" size="sm">Move stock</x-button>
                                @endcan
                            </td>
                        </tr>

                        @if ($adjustingId === $product->id)
                            <tr class="bg-slate-50/60">
                                <td colspan="6" class="px-4 py-4">
                                    <form wire:submit="postMovement" class="space-y-4">
                                        <p class="text-sm font-semibold text-slate-900">
                                            Record a stock movement for {{ $product->name }}
                                        </p>

                                        <div class="grid gap-4 sm:grid-cols-3">
                                            <x-field label="Movement" name="movementType"
                                                     :error="$errors->first('movementType')">
                                                <select id="movementType" wire:model="movementType"
                                                        class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                                    @foreach ($postableTypes as $type)
                                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </x-field>

                                            <x-field label="Quantity" name="quantity" :error="$errors->first('quantity')"
                                                     hint="An adjustment may be negative to record loss or a miscount.">
                                                <x-input id="quantity" type="number" wire:model="quantity"
                                                         :error="$errors->has('quantity')" required />
                                            </x-field>

                                            <x-field label="Reason" name="reason" :error="$errors->first('reason')">
                                                <x-input id="reason" wire:model="reason"
                                                         :error="$errors->has('reason')" required />
                                            </x-field>
                                        </div>

                                        <div class="flex justify-end gap-2">
                                            <x-button type="button" wire:click="cancelAdjustment"
                                                      variant="secondary" size="sm">Cancel</x-button>
                                            <x-button type="submit" variant="primary" size="sm"
                                                      wire:loading.attr="disabled">Record movement</x-button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10">
                                <x-empty-state title="No products"
                                               description="Create a product before recording stock." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    {{-- Movement history --}}
    <h2 class="mb-3 text-lg font-semibold text-slate-900">Movement history</h2>

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">When</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Product</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Movement</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Change</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">On hand after</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Reason</th>
                        <th scope="col" class="px-4 py-3 font-semibold">By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($movements as $movement)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                                {{ $movement->created_at->format('j M Y, H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $movement->product?->name }}</span>
                                <span class="block font-mono text-xs text-slate-400">{{ $movement->product?->sku }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :classes="$movement->type->badgeClasses()">
                                    {{ $movement->type->label() }}
                                </x-badge>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $movement->quantity_delta > 0 ? 'text-emerald-700' : 'text-slate-900' }}">
                                {{ $movement->signedDelta() }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ number_format($movement->stock_on_hand_after) }}
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ $movement->reason ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">
                                {{ $movement->creator?->name ?? ($movement->created_by ? 'Staff #'.$movement->created_by : 'System') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10">
                                <x-empty-state title="No stock movements yet"
                                               description="Every change to stock will be listed here." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($movements->hasPages())
            <div class="border-t border-slate-100 p-4">{{ $movements->links() }}</div>
        @endif
    </x-card>
</div>

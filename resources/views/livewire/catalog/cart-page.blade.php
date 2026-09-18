{{-- The unified view of everything a customer is still buying in the Shop.

     Two states, clearly separated and never mixed:
     - An "Awaiting payment" order (if one is owed): a frozen, already-reserved
       checkout whose lines cannot be edited here. It stays visible until it is
       paid, cancelled or expires, so placing an order never looks like the
       purchase vanished.
     - The editable basket: intent only, nothing reserved.

     Every figure is computed on the server from product rows and frozen order
     snapshots; the browser only ever sends a line id and a quantity. --}}

<div>
    <x-page-header
        title="Your cart"
        :description="'Everything you are buying in one place, before you pay once.'"/>

    @if (session('cart-added'))
        <x-alert variant="success" class="mb-6" role="status">{{ session('cart-added') }}</x-alert>
    @endif

    @error('place')
        <x-alert variant="danger" class="mb-6" role="alert">{{ $message }}</x-alert>
    @enderror

    @if ($payableOrder)
        <x-card title="Awaiting payment"
                subtitle="Order {{ $payableOrder->order_number }} — set aside when you placed it."
                class="mb-6">
            <div class="space-y-4">
                @foreach ($payableOrder->items as $item)
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900">{{ $item->product_name_snapshot }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $item->sku_snapshot }}</p>
                        </div>

                        <div class="shrink-0 text-right">
                            @if ($item->quantity > 1)
                                <p class="text-sm tabular-nums text-slate-600">
                                    {{ $item->quantity }} &times; <x-money :amount="$item->unitPrice()" />
                                </p>
                            @endif
                            <p class="font-semibold tabular-nums text-slate-900">
                                <x-money :amount="$item->lineTotal()" />
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>

            <dl class="mt-5 space-y-3 border-t border-slate-100 pt-4 text-sm">
                <div class="flex items-baseline justify-between gap-4">
                    <dt class="text-slate-600">Subtotal</dt>
                    <dd class="font-semibold tabular-nums text-slate-900">
                        <x-money :amount="$payableOrder->subtotal()" />
                    </dd>
                </div>

                @if ($payableOrder->delivery_minor > 0)
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">Delivery</dt>
                        <dd class="font-semibold tabular-nums text-slate-900">
                            <x-money :amount="$payableOrder->deliveryFee()" />
                        </dd>
                    </div>
                @endif

                @if ($payableOrder->tax_minor > 0)
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">Tax</dt>
                        <dd class="font-semibold tabular-nums text-slate-900">
                            <x-money :amount="$payableOrder->tax()" />
                        </dd>
                    </div>
                @endif

                @if ($payableOrder->store_wallet_applied_minor > 0)
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">Applied from your Store Wallet</dt>
                        <dd class="font-semibold tabular-nums text-emerald-700">
                            −<x-money :amount="$payableOrder->storeWalletApplied()" />
                        </dd>
                    </div>
                @endif

                <div class="flex items-baseline justify-between gap-4 border-t border-slate-200 pt-3">
                    <dt class="text-base font-semibold text-slate-900">Total to pay</dt>
                    <dd class="text-xl font-bold tabular-nums text-slate-900">
                        <x-money :amount="$payableOrder->payable()" />
                    </dd>
                </div>
            </dl>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <x-button href="{{ route('checkout.show', $payableOrder) }}" wire:navigate>
                    Continue to payment
                </x-button>
                <x-button variant="ghost" href="{{ route('products.index') }}" wire:navigate>
                    Keep shopping
                </x-button>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                This order was set aside for you when you placed it. Its lines are fixed and cannot be
                edited here. Paying completes it; if it expires or you cancel it, the items go back on sale.
            </p>
        </x-card>
    @endif

    @if ($lines->isEmpty())
        @unless ($payableOrder)
            <x-card>
                <p class="text-sm text-slate-600">Your cart is empty.</p>
                <x-button class="mt-4" href="{{ route('products.index') }}" wire:navigate>Browse products</x-button>
            </x-card>
        @endunless
    @else
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <x-card title="Your items">
                    @foreach ($lines as $line)
                        @php($state = $availability[$line->product_id] ?? null)
                        {{-- @php($state) reads the pre-computed, server-side availability for
                             this product; it decides nothing. --}}

                        <div class="{{ $loop->first ? '' : 'mt-4 border-t border-slate-100 pt-4' }}">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <a href="{{ route('products.show', $line->product->slug) }}" wire:navigate
                                       class="font-semibold text-slate-900 hover:text-brand-700">
                                        {{ $line->product->name }}
                                    </a>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $line->product->sku }}</p>

                                    @unless ($state?->obtainable)
                                        @if ($state?->hasAuction())
                                            <p class="mt-2 text-xs font-medium text-accent-700">
                                                This product is in an auction right now.
                                                <a href="{{ route('auctions.show', $state->auction) }}" wire:navigate
                                                   class="underline">View the auction</a>.
                                            </p>
                                        @else
                                            <p class="mt-2 text-xs font-medium text-slate-500">
                                                This product is not available to buy right now.
                                            </p>
                                        @endif
                                    @endunless
                                </div>

                                <div class="flex items-center gap-3">
                                    <x-field label="Qty" :name="'quantities.'.$line->id" class="w-24">
                                        <input type="number" min="0"
                                               wire:model="quantities.{{ $line->id }}"
                                               id="quantities.{{ $line->id }}"
                                               class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                    </x-field>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="text-xs text-slate-500">
                                        {{ $line->quantity }} &times; <x-money :amount="$line->product->buyNowPrice()" />
                                    </p>
                                    <p class="mt-0.5 font-semibold tabular-nums text-slate-900">
                                        <x-money :amount="$lineSubtotals[$line->id]" />
                                    </p>
                                </div>
                            </div>

                            @error('quantities.'.$line->id)
                                <p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>
                            @enderror

                            <div class="mt-3">
                                <x-button type="button" variant="ghost" size="sm"
                                          wire:click="removeLine({{ $line->id }})"
                                          wire:loading.attr="disabled">
                                    Remove
                                </x-button>
                            </div>
                        </div>
                    @endforeach

                    <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                        <x-button type="button" variant="secondary" wire:click="updateQuantities"
                                  wire:loading.attr="disabled">
                            Update cart
                        </x-button>
                        <x-button type="button" variant="ghost" wire:click="clearCart"
                                  wire:confirm="Empty your cart?"
                                  wire:loading.attr="disabled">
                            Empty cart
                        </x-button>
                    </div>
                </x-card>
            </div>

            <div class="space-y-6">
                <x-card title="Summary" subtitle="Frozen server-side when you place your order.">
                    @if ($pricingError !== null)
                        <x-alert variant="danger">{{ $pricingError }}</x-alert>
                    @elseif ($pricing !== null)
                        <dl class="space-y-3 text-sm">
                            <div class="flex items-baseline justify-between gap-4">
                                <dt class="text-slate-600">Items</dt>
                                <dd class="font-semibold tabular-nums text-slate-900">
                                    <x-money :amount="$pricing->subtotal" />
                                </dd>
                            </div>

                            @if ($pricing->delivery->isPositive())
                                <div class="flex items-baseline justify-between gap-4">
                                    <dt class="text-slate-600">Delivery</dt>
                                    <dd class="font-semibold tabular-nums text-slate-900">
                                        <x-money :amount="$pricing->delivery" />
                                    </dd>
                                </div>
                            @endif

                            @if ($pricing->tax->isPositive())
                                <div class="flex items-baseline justify-between gap-4">
                                    <dt class="text-slate-600">Tax</dt>
                                    <dd class="font-semibold tabular-nums text-slate-900">
                                        <x-money :amount="$pricing->tax" />
                                    </dd>
                                </div>
                            @endif

                            @if ($pricing->storeWalletApplied->isPositive())
                                <div class="flex items-baseline justify-between gap-4">
                                    <dt class="text-slate-600">Applied from your Store Wallet</dt>
                                    <dd class="font-semibold tabular-nums text-emerald-700">
                                        −<x-money :amount="$pricing->storeWalletApplied" />
                                    </dd>
                                </div>
                            @endif

                            <div class="flex items-baseline justify-between gap-4 border-t border-slate-200 pt-3">
                                <dt class="text-base font-semibold text-slate-900">Total to pay</dt>
                                <dd class="text-xl font-bold tabular-nums text-slate-900">
                                    <x-money :amount="$pricing->payable" />
                                </dd>
                            </div>
                        </dl>
                    @endif
                </x-card>

                @if ($pricing !== null)
                    <x-button wire:click="placeOrder" class="w-full"
                              wire:loading.attr="disabled" wire:target="placeOrder">
                        <span wire:loading.remove wire:target="placeOrder">Place order</span>
                        <span wire:loading wire:target="placeOrder">Placing your order…</span>
                    </x-button>

                    <p class="text-xs text-slate-500">
                        Placing your order is the moment the items are set aside for you and the
                        total to pay is frozen. If anything is no longer available, the whole order
                        is held back and nothing is charged.
                    </p>
                @endif
            </div>
        </div>
    @endif
</div>
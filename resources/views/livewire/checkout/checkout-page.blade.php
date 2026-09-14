{{-- What a customer reviews before paying.

     Every figure was frozen when this checkout was opened and is displayed as
     stored. Nothing is recalculated here, and no amount is submitted from the
     browser: paying sends an intent, and the server reads the total off the
     order.

     Credits appear on this page only as a count, explaining where a discount
     came from. They are never written with a currency symbol. --}}

<div>
    <x-page-header
        title="Checkout"
        :description="'Order '.$order->order_number" />

    @error('payment')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    @unless ($order->isAwaitingPayment())
        <x-alert variant="info" class="mb-6">
            This checkout is {{ strtolower($order->status->label()) }}. Nothing further can be paid
            against it.
        </x-alert>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
    {{-- Where it should go. Not required before paying: payment and delivery
         are separate concerns, and an auction winner never sees this page at
         all. The address is captured before the package can be prepared,
         which is where it actually matters. --}}
    <x-card title="Delivery address" class="mb-6">
        @if (session('checkout-address'))
            <x-alert variant="success" class="mb-4">{{ session('checkout-address') }}</x-alert>
        @endif

        @error('address')
            <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
        @enderror

        @if ($addresses->isEmpty())
            <p class="text-sm text-slate-600">
                You have no saved addresses. You can pay now and tell us where to send it
                afterwards, or add one first.
            </p>
            <x-button class="mt-3" variant="ghost" href="{{ route('addresses.index') }}" wire:navigate>
                Add a delivery address
            </x-button>
        @else
            <form wire:submit="chooseAddress" class="space-y-4">
                <x-field label="Deliver to" name="deliveryAddressId">
                    <select wire:model="deliveryAddressId" id="deliveryAddressId"
                            class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                        <option value="">Choose an address</option>
                        @foreach ($addresses as $address)
                            <option value="{{ $address->id }}">
                                {{ $address->displayLabel() }} — {{ $address->summary() }}
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <div class="flex flex-wrap gap-3">
                    <x-button type="submit" variant="secondary" size="sm">Use this address</x-button>
                    <x-button type="button" variant="ghost" size="sm"
                              href="{{ route('addresses.index') }}" wire:navigate>
                        Manage addresses
                    </x-button>
                </div>
            </form>
        @endif
    </x-card>

            <x-card title="What you are buying">
                @if ($item)
                    <div class="flex items-start justify-between gap-6">
                        <div>
                            {{-- The snapshot, not the product's current name. This
                                 order must describe itself even after the product
                                 is renamed. --}}
                            <p class="font-semibold text-slate-900">{{ $item->product_name_snapshot }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $item->sku_snapshot }}</p>
                        </div>

                        <x-badge :classes="$order->source->badgeClasses()">
                            {{ $order->source->label() }}
                        </x-badge>
                    </div>
                @endif

                @if ($order->source === App\Enums\OrderSource::AuctionWin)
                    <div class="mt-5 rounded-lg bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
                        <p class="text-sm text-slate-700">
                            You won this auction with the highest valid credit bid of
                            <strong>{{ number_format($pricing->winningBidCredits ?? 0) }} credits</strong>.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            Those credits were consumed when you bid and are not charged again here.
                            What you pay now is this auction's settlement amount — a separate GH₵
                            figure, and <strong>not</strong> your bid converted into cedis.
                        </p>
                    </div>
                @elseif ($order->endedAnAuction())
                    <div class="mt-5 rounded-lg bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
                        <p class="text-sm text-slate-700">
                            Paying for this buys the product outright and <strong>ends the auction
                            immediately</strong>. The current highest bidder will not win.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            The auction is still running until your payment is confirmed — opening
                            this checkout does not end it.
                        </p>
                    </div>
                @endif
            </x-card>

            <x-card title="What you pay" subtitle="Frozen when this checkout was opened.">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">
                            {{ $order->source === App\Enums\OrderSource::AuctionWin
                                ? 'Auction Settlement Amount'
                                : 'Buy Now price' }}
                        </dt>
                        <dd class="font-semibold tabular-nums text-slate-900">
                            <x-money :amount="$order->subtotal()" />
                        </dd>
                    </div>

                    @if ($pricing->hasDiscount())
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-600">
                                Credit discount
                                {{-- A count of credits and the cedis they earned, side
                                     by side and clearly different quantities. The value
                                     comes from what the credits actually cost, not a
                                     fixed per-credit rate. --}}
                                <span class="block text-xs text-slate-500">
                                    {{ number_format($pricing->discountCredits) }} credits you already
                                    consumed bidding on this auction, valued at what those credits
                                    actually cost
                                </span>
                            </dt>
                            <dd class="font-semibold tabular-nums text-emerald-700">
                                −<x-money :amount="$order->discount()" />
                            </dd>
                        </div>
                    @endif

                    @if ($order->delivery_minor > 0)
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-600">Delivery</dt>
                            <dd class="font-semibold tabular-nums text-slate-900">
                                <x-money :amount="$order->deliveryFee()" />
                            </dd>
                        </div>
                    @endif

                    @if ($order->tax_minor > 0)
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-600">Tax</dt>
                            <dd class="font-semibold tabular-nums text-slate-900">
                                <x-money :amount="$order->tax()" />
                            </dd>
                        </div>
                    @endif

                    @if ($order->store_wallet_applied_minor > 0)
                        <div class="flex items-baseline justify-between gap-4">
                            {{-- Store Wallet value is part of the bill, and it is not
                                 something a provider was asked for. --}}
                            <dt class="text-slate-600">Applied from your Store Wallet</dt>
                            <dd class="font-semibold tabular-nums text-emerald-700">
                                −<x-money :amount="$order->storeWalletApplied()" />
                            </dd>
                        </div>
                    @endif

                    <div class="flex items-baseline justify-between gap-4 border-t border-slate-200 pt-3">
                        <dt class="text-base font-semibold text-slate-900">Total to pay</dt>
                        <dd class="text-xl font-bold tabular-nums text-slate-900">
                            <x-money :amount="$order->payable()" />
                        </dd>
                    </div>
                </dl>

                @if ($pricing->hasDiscount())
                    <x-alert variant="warning" class="mt-5">
                        Your {{ number_format($pricing->discountCredits) }} consumed credits stay
                        consumed. They reduce this price — they are not refunded, not converted to
                        cash, and not returned to your wallet.
                    </x-alert>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Pay">
                @if ($order->isPayable())
                    <p class="text-sm text-slate-600">
                        You will be taken to Paystack to pay
                        <strong><x-money :amount="$order->payable()" /></strong>.
                        @if ($order->store_wallet_applied_minor > 0)
                            The rest of the total came from your Store Wallet.
                        @endif
                    </p>

                    @if ($order->store_wallet_applied_minor > 0)
                        <p class="mt-2 text-xs text-slate-500">
                            Your Store Wallet covers
                            <strong><x-money :amount="$order->storeWalletApplied()" /></strong>
                            of this bill.
                        </p>
                    @endif

                    <p class="mt-2 text-xs text-slate-500">
                        Your order is confirmed only once we have verified the payment with Paystack
                        ourselves — not when your browser returns.
                    </p>

                    <x-button wire:click="pay" class="mt-4 w-full" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="pay">Pay with Paystack</span>
                        <span wire:loading wire:target="pay">Preparing…</span>
                    </x-button>

                    <x-button variant="ghost" wire:click="cancel" class="mt-2 w-full">
                        Cancel this checkout
                    </x-button>
                @elseif ($order->hasExpired() && $order->isAwaitingPayment())
                    <x-alert variant="warning">
                        This checkout has expired. Anything it was holding has gone back on sale.
                    </x-alert>
                @else
                    <x-badge :classes="$order->status->badgeClasses()">
                        {{ $order->status->label() }}
                    </x-badge>

                    <x-button variant="secondary" size="sm" class="mt-4 w-full"
                              href="{{ route('orders.show', $order) }}" wire:navigate>
                        View this order
                    </x-button>
                @endif
            </x-card>

            @if ($order->isAwaitingPayment() && $order->payment_due_at)
                <x-card title="Pay by">
                    <p class="text-sm font-semibold text-slate-900">
                        {{ $order->payment_due_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                    </p>
                    <p class="mt-2 text-sm text-slate-600">
                        @if ($order->holds_reservation)
                            One unit is held for you until then. After that it goes back on sale.
                        @else
                            After this the checkout closes and you would need to start again.
                        @endif
                    </p>
                </x-card>
            @endif
        </div>
    </div>
</div>

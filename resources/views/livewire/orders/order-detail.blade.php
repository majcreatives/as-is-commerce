{{-- One order, as the customer sees it. Read-only: nothing here changes an
     amount or a status. --}}

<div>
    <div class="mb-6 flex flex-wrap items-center gap-2">
        <x-badge :classes="$order->status->badgeClasses()">{{ $order->status->label() }}</x-badge>
        <x-badge :classes="$order->source->badgeClasses()">{{ $order->source->label() }}</x-badge>
    </div>

    <x-page-header
        :title="'Order '.$order->order_number"
        :description="$item?->product_name_snapshot" />

    @if ($order->isFulfilmentBlocked())
        <x-alert variant="warning" class="mb-6">
            Your payment went through, but we could not complete this order. Our team has it and
            will be in touch.
        </x-alert>
    @endif

    {{-- What is true about the money, and nothing more. A refund is only
         described as done once the provider has confirmed it — never while it
         is still on its way, and never as a promise of one that has not been
         started. --}}
    @if ($refunds->isNotEmpty())
        <x-card title="Refund" class="mb-6">
            <ul class="space-y-4 text-sm">
                @foreach ($refunds as $refund)
                    <li class="flex flex-wrap items-baseline justify-between gap-3">
                        <div>
                            <x-badge :classes="$refund->status->badgeClasses()">
                                {{ $refund->status->label() }}
                            </x-badge>
                            <p class="mt-2 text-slate-700">
                                @switch($refund->status)
                                    @case(App\Enums\RefundStatus::Succeeded)
                                        We returned this to the payment method you used on
                                        {{ $refund->succeeded_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y') }}.
                                        @break
                                    @case(App\Enums\RefundStatus::Processing)
                                        We have asked our payment provider to return this. We will
                                        confirm as soon as it is done.
                                        @break
                                    @default
                                        This refund did not go through. Nothing has been taken from
                                        you, and our team has been notified.
                                @endswitch
                            </p>
                            @if ($order->auction_id)
                                <p class="mt-2 text-xs text-slate-500">
                                    Any Credits you spent bidding remain consumed. This returns
                                    cedis only.
                                </p>
                            @endif
                        </div>
                        <span class="text-lg font-bold tabular-nums text-slate-900">
                            <x-money :amount="$refund->amount()" />
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="What you paid">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">
                            {{ $order->source === App\Enums\OrderSource::AuctionWin
                                ? 'Auction Settlement Amount' : 'Buy Now price' }}
                        </dt>
                        <dd class="font-semibold tabular-nums text-slate-900">
                            <x-money :amount="$order->subtotal()" />
                        </dd>
                    </div>

                    @if ($pricing->hasDiscount())
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-600">
                                Credit discount
                                <span class="block text-xs text-slate-500">
                                    {{ number_format($pricing->discountCredits) }} credits consumed
                                    bidding on this auction
                                </span>
                            </dt>
                            <dd class="font-semibold tabular-nums text-emerald-700">
                                &minus;<x-money :amount="$order->discount()" />
                            </dd>
                        </div>
                    @endif

                    @if ($order->delivery_minor > 0)
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-600">Delivery</dt>
                            <dd class="tabular-nums text-slate-900"><x-money :amount="$order->delivery()" /></dd>
                        </div>
                    @endif

                    @if ($order->tax_minor > 0)
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-600">Tax</dt>
                            <dd class="tabular-nums text-slate-900"><x-money :amount="$order->tax()" /></dd>
                        </div>
                    @endif

                    <div class="flex items-baseline justify-between gap-4 border-t border-slate-200 pt-3">
                        <dt class="text-base font-semibold text-slate-900">Total</dt>
                        <dd class="text-xl font-bold tabular-nums text-slate-900">
                            <x-money :amount="$order->total()" />
                        </dd>
                    </div>
                </dl>
            </x-card>

            @if ($order->source === App\Enums\OrderSource::AuctionWin)
                <x-card title="How you won this">
                    <p class="text-sm text-slate-700">
                        You held the highest valid credit bid of
                        <strong>{{ number_format($pricing->winningBidCredits ?? 0) }} credits</strong>
                        when the auction closed.
                    </p>
                    <p class="mt-2 text-sm text-slate-600">
                        Those credits were consumed when you bid. They were not charged again here,
                        and they were not converted into GH&#8373; — the amount above is this
                        auction's own settlement amount.
                    </p>
                </x-card>
            @endif

            <x-card title="Payments" subtitle="Every attempt, including any that did not complete."
                    :padded="false">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Reference</th>
                                <th class="px-5 py-3 font-semibold">Amount</th>
                                <th class="px-5 py-3 font-semibold">Status</th>
                                <th class="px-5 py-3 font-semibold">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($payments as $payment)
                                <tr>
                                    <td class="px-5 py-3 font-mono text-xs text-slate-600">
                                        {{ $payment->provider_reference }}
                                    </td>
                                    <td class="px-5 py-3 tabular-nums text-slate-900">
                                        <x-money :amount="$payment->amount()" />
                                    </td>
                                    <td class="px-5 py-3">
                                        <x-badge :classes="$payment->status->badgeClasses()">
                                            {{ $payment->status->label() }}
                                        </x-badge>
                                    </td>
                                    <td class="px-5 py-3 text-slate-500">
                                        {{ ($payment->paid_at ?? $payment->created_at)->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-4 text-slate-500">
                                        No payment has been started yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <div class="space-y-6">
            @if ($order->isPayable())
                <x-card title="Awaiting payment">
                    <p class="text-sm text-slate-600">
                        This order is not confirmed until we have verified a payment.
                    </p>
                    <x-button class="mt-4 w-full" href="{{ route('checkout.show', $order) }}"
                              wire:navigate>Go to checkout</x-button>
                </x-card>
            @endif

            <x-card title="Progress" :padded="false">
                <ul class="divide-y divide-slate-100">
                    @foreach ($order->transitions()->orderBy('id')->get() as $transition)
                        <li class="px-5 py-3">
                            <p class="text-sm font-medium text-slate-900">
                                {{ $transition->to_status->label() }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ $transition->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                            </p>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            @if ($item)
                <x-card title="Item">
                    <p class="text-sm font-semibold text-slate-900">{{ $item->product_name_snapshot }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ $item->sku_snapshot }}</p>

                    @if ($item->product)
                        <x-button variant="secondary" size="sm" class="mt-4"
                                  href="{{ route('products.show', $item->product->slug) }}" wire:navigate>
                            View product
                        </x-button>
                    @endif
                </x-card>
            @endif
        </div>
    </div>
</div>

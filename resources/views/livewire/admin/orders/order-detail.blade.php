{{-- One order, in full.

     There is no form here for an amount, a winning bid, or for marking an
     order paid. Those are frozen or unreachable by design, and a control that
     always failed would be worse than none. --}}

<div>
    <x-admin.nav />

    <div class="mb-6 flex flex-wrap items-center gap-2">
        <x-badge :classes="$order->status->badgeClasses()">{{ $order->status->label() }}</x-badge>
        <x-badge :classes="$order->source->badgeClasses()">{{ $order->source->label() }}</x-badge>

        @if ($order->isFulfilmentBlocked())
            <x-badge classes="bg-amber-50 text-amber-800 ring-amber-200">Needs attention</x-badge>
        @endif
    </div>

    <x-page-header
        :title="'Order '.$order->order_number"
        :description="$order->user?->name" />

    @error('lifecycle')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    @if ($order->isFulfilmentBlocked())
        <x-alert variant="warning" class="mb-6">
            <p class="font-semibold">Paid, but nothing could be delivered.</p>
            <p class="mt-1">{{ $order->fulfilment_blocked_reason }}</p>
            <p class="mt-2">
                The payment is real and recorded. What is owed to this customer is a decision for a
                person — the platform will not refund anything on its own.
            </p>
        </x-alert>
    @endif

    @error('refund')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    {{-- The confirmation. Nothing moves until this is confirmed: returning
         somebody's money is not a thing to do on a stray click. --}}
    @if ($confirmingRefund && $refundablePayment)
        <x-card title="Confirm this refund"
                subtitle="Read it back before anything is sent to the provider."
                class="mb-6 ring-2 ring-amber-300">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-slate-600">Order</dt>
                    <dd class="font-semibold text-slate-900">{{ $order->order_number }}</dd>
                </div>
                <div>
                    <dt class="text-slate-600">Customer</dt>
                    <dd class="font-semibold text-slate-900">{{ $order->user?->name }}</dd>
                </div>
                <div>
                    <dt class="text-slate-600">Originally paid</dt>
                    <dd class="font-semibold tabular-nums text-slate-900">
                        <x-money :amount="$refundablePayment->amount()" />
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-600">Already returned</dt>
                    <dd class="tabular-nums text-slate-900"><x-money :amount="$refunded" /></dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-slate-600">This refund</dt>
                    <dd class="text-xl font-bold tabular-nums text-slate-900">
                        <x-money :amount="$refundable" />
                    </dd>
                </div>
            </dl>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <x-field label="Why" name="refundReason">
                    <select wire:model="refundReason" id="refundReason"
                            class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                        @foreach ($reasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Note (optional)" name="refundNote">
                    <input type="text" wire:model="refundNote" id="refundNote" maxlength="500"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>
            </div>

            <x-alert variant="warning" class="mt-5">
                <p class="font-semibold">What this does, and what it does not.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Asks the provider to return <x-money :amount="$refundable" /> to the customer.</li>
                    <li>Leaves the original payment exactly as it is. It stays a successful payment.</li>
                    <li>Returns no Credits. Bid credits are consumed permanently and are not affected.</li>
                    <li>Puts no stock back. Inventory is not changed by a refund.</li>
                    <li>Cannot be undone here. A mistake is answered by a new financial act.</li>
                </ul>
            </x-alert>

            <div class="mt-5 flex flex-wrap gap-3">
                @can('refunds.process')
                    <x-button wire:click="confirmRefund" wire:loading.attr="disabled">
                        Refund <x-money :amount="$refundable" />
                    </x-button>
                @else
                    <p class="text-sm text-slate-600">
                        You may record a refund but not send one. Someone holding
                        <span class="font-mono text-xs">refunds.process</span> has to complete it.
                    </p>
                @endcan

                <x-button variant="ghost" wire:click="cancelRefund">Cancel</x-button>
            </div>
        </x-card>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="What was charged" subtitle="Frozen at checkout. Immutable once paid.">
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

                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">
                            Credit discount
                            @if ($pricing->discountCredits > 0)
                                {{-- The count and the cedis, side by side: two different
                                     quantities, and the page says so. --}}
                                <span class="block text-xs text-slate-500">
                                    {{ number_format($pricing->discountCredits) }} consumed bid credits at
                                    <x-money :amount="App\Domain\Shared\Money\Money::fromMinor($pricing->discountRateMinorPerCredit, $order->currency)" />
                                    each
                                </span>
                            @endif
                        </dt>
                        <dd class="tabular-nums text-slate-900">
                            @if ($pricing->hasDiscount())
                                &minus;<x-money :amount="$order->discount()" />
                            @else
                                —
                            @endif
                        </dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">Delivery</dt>
                        <dd class="tabular-nums text-slate-900"><x-money :amount="$order->delivery()" /></dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">Tax ({{ $pricing->taxBps }} bps)</dt>
                        <dd class="tabular-nums text-slate-900"><x-money :amount="$order->tax()" /></dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-4 border-t border-slate-200 pt-3">
                        <dt class="text-base font-semibold text-slate-900">Total</dt>
                        <dd class="text-xl font-bold tabular-nums text-slate-900">
                            <x-money :amount="$order->total()" />
                        </dd>
                    </div>
                </dl>
            </x-card>

            @if ($order->auction_id)
                <x-card title="The auction behind this">
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Auction</dt>
                            <dd>
                                <a href="{{ route('admin.auctions.show', $order->auction_id) }}"
                                   wire:navigate class="font-semibold text-brand-800 underline">
                                    #{{ $order->auction_id }}
                                </a>
                            </dd>
                        </div>

                        @if ($order->source === App\Enums\OrderSource::AuctionWin)
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-600">Won with</dt>
                                {{-- Credits, as a count. Never shown as money. --}}
                                <dd class="tabular-nums font-semibold text-slate-900">
                                    {{ number_format($pricing->winningBidCredits ?? 0) }} credits
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-600">Winning bid</dt>
                                <dd class="text-slate-900">#{{ $order->winning_bid_id }}</dd>
                            </div>
                        @else
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-600">Effect on the auction</dt>
                                <dd class="text-slate-900">
                                    {{ $order->isPaid() ? 'Ended it by Buy Now' : 'Would end it, once paid' }}
                                </dd>
                            </div>
                        @endif

                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-600">Product Buy Now price</dt>
                            <dd class="tabular-nums text-slate-500">
                                <x-money :amount="$order->auction->product->buyNowPrice()" />
                            </dd>
                        </div>
                    </dl>

                    @if ($order->source === App\Enums\OrderSource::AuctionWin)
                        <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
                            The settlement amount and the Buy Now price are independent figures, and
                            neither is derived from the winning bid. The bid is a count of credits,
                            already consumed.
                        </p>
                    @endif
                </x-card>
            @endif

            @can('order_payments.view')
                <x-card title="Payment attempts"
                        subtitle="What was asked of the provider, and what came back." :padded="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Reference</th>
                                    <th class="px-5 py-3 font-semibold">Asked for</th>
                                    <th class="px-5 py-3 font-semibold">Status</th>
                                    <th class="px-5 py-3 font-semibold">Provider txn</th>
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
                                        <td class="px-5 py-3 text-xs text-slate-500">
                                            {{ $payment->provider_transaction_id ?? '—' }}
                                            @if ($payment->provider_channel)
                                                <span class="block">{{ $payment->provider_channel }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-slate-500">
                                            {{ ($payment->paid_at ?? $payment->created_at)->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-5 py-4 text-slate-500">
                                            No payment has been started.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @endcan

            {{-- Money returned, as its own record. The payment attempts above
                 are untouched by any of this: what was paid and what was
                 given back are two questions with two answers. --}}
            @can('refunds.view')
                <x-card title="Refunds" :padded="false">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-4 text-sm">
                            <span class="text-slate-600">Returned so far</span>
                            <span class="font-semibold tabular-nums text-slate-900">
                                <x-money :amount="$refunded" />
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-baseline justify-between gap-4 text-sm">
                            <span class="text-slate-600">Still refundable</span>
                            <span class="font-semibold tabular-nums text-slate-900">
                                <x-money :amount="$refundable" />
                            </span>
                        </div>

                        @if ($canRefund && ! $confirmingRefund)
                            <x-button class="mt-4" wire:click="startRefund">
                                Refund this payment
                            </x-button>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Amount</th>
                                    <th class="px-5 py-3 font-semibold">Status</th>
                                    <th class="px-5 py-3 font-semibold">Reason</th>
                                    <th class="px-5 py-3 font-semibold">Requested by</th>
                                    <th class="px-5 py-3 font-semibold">Provider</th>
                                    <th class="px-5 py-3 font-semibold">When</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($refunds as $refund)
                                    <tr>
                                        <td class="px-5 py-3 tabular-nums font-semibold text-slate-900">
                                            <x-money :amount="$refund->amount()" />
                                        </td>
                                        <td class="px-5 py-3">
                                            <x-badge :classes="$refund->status->badgeClasses()">
                                                {{ $refund->status->label() }}
                                            </x-badge>
                                            @if ($refund->failure_reason)
                                                <span class="mt-1 block text-xs text-red-700">
                                                    {{ $refund->failure_reason }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-slate-600">
                                            {{ $refund->reason->label() }}
                                            @if ($refund->note)
                                                <span class="mt-1 block text-xs text-slate-500">
                                                    {{ $refund->note }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-slate-600">
                                            {{ $refund->requestedBy?->name ?? '—' }}
                                        </td>
                                        <td class="px-5 py-3 font-mono text-xs text-slate-500">
                                            {{ $refund->provider_reference ?? '—' }}
                                            @if ($refund->provider_status)
                                                <span class="block">{{ $refund->provider_status }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-slate-500">
                                            {{ $refund->requested_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-5 py-4 text-slate-500">
                                            Nothing has been refunded on this order.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @endcan

            <x-card title="History" :padded="false">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Change</th>
                            <th class="px-5 py-3 font-semibold">Reason</th>
                            <th class="px-5 py-3 font-semibold">By</th>
                            <th class="px-5 py-3 font-semibold">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($order->transitions()->with('causedBy')->orderBy('id')->get() as $transition)
                            <tr>
                                <td class="px-5 py-3 text-slate-900">
                                    {{ $transition->from_status?->label() ?? 'Created' }}
                                    &rarr; {{ $transition->to_status->label() }}
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $transition->reason }}</td>
                                <td class="px-5 py-3 text-slate-500">
                                    {{ $transition->causedBy?->name ?? 'System' }}
                                </td>
                                <td class="px-5 py-3 text-slate-500">
                                    {{ $transition->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Customer">
                <p class="text-sm font-semibold text-slate-900">{{ $order->user?->name }}</p>
                <p class="mt-0.5 text-xs text-slate-500">{{ $order->user?->phone }}</p>

                @can('wallets.inspect')
                    <x-button variant="secondary" size="sm" class="mt-4"
                              href="{{ route('admin.wallets.show', $order->user_id) }}" wire:navigate>
                        View wallet
                    </x-button>
                @endcan
            </x-card>

            @if ($item)
                <x-card title="Item">
                    <p class="text-sm font-semibold text-slate-900">{{ $item->product_name_snapshot }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ $item->sku_snapshot }}</p>
                    <p class="mt-3 text-xs text-slate-500">
                        Snapshotted at checkout. The product may have been renamed or repriced
                        since; this order still says what was actually bought.
                    </p>
                </x-card>
            @endif

            @can('orders.manage')
                @if ($order->isPaid() && ! $order->status->isTerminal())
                    <x-card title="Move this along"
                            subtitle="Operational progress only. Every move is recorded.">
                        @if ($order->status === App\Enums\OrderStatus::Paid)
                            <x-button wire:click="advance('processing')" class="w-full">
                                Mark as processing
                            </x-button>
                        @endif

                        <x-button variant="secondary" wire:click="advance('fulfilled')"
                                  class="mt-2 w-full">
                            Mark as fulfilled
                        </x-button>

                        <p class="mt-3 text-xs text-slate-500">
                            There is no control to mark an order paid. That state is reachable only
                            through a payment verified with the provider.
                        </p>
                    </x-card>
                @endif
            @endcan

            <x-card title="Checkout window">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Placed</dt>
                        <dd class="text-slate-900">
                            {{ $order->placed_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Due</dt>
                        <dd class="text-slate-900">
                            {{ $order->payment_due_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Paid</dt>
                        <dd class="text-slate-900">
                            {{ $order->paid_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') ?? '—' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Holds stock</dt>
                        <dd class="text-slate-900">{{ $order->holds_reservation ? 'Yes' : 'No' }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>
    </div>
</div>

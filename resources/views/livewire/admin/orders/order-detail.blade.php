{{-- One order, in full.

     There is no form here for an amount, a winning bid, or for marking an
     order paid. Those are frozen or unreachable by design, and a control that
     always failed would be worse than none. --}}

<x-admin.shell>

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
                                     quantities, and the page says so. Valued at what the
                                     credits actually cost, never a fixed rate. --}}
                                <span class="block text-xs text-slate-500">
                                    {{ number_format($pricing->discountCredits) }} consumed bid credits,
                                    valued at what those credits actually cost
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
                        <dd class="tabular-nums text-slate-900"><x-money :amount="$order->deliveryFee()" /></dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">Tax ({{ $pricing->taxBps }} bps)</dt>
                        <dd class="tabular-nums text-slate-900"><x-money :amount="$order->tax()" /></dd>
                    </div>

                    @if ($order->store_wallet_applied_minor > 0)
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-600">Applied from Store Wallet</dt>
                            <dd class="tabular-nums text-slate-900">
                                &minus;<x-money :amount="$order->storeWalletApplied()" />
                            </dd>
                        </div>
                    @endif

                    <div class="flex items-baseline justify-between gap-4 border-t border-slate-200 pt-3">
                        <dt class="text-base font-semibold text-slate-900">Total</dt>
                        <dd class="text-xl font-bold tabular-nums text-slate-900">
                            <x-money :amount="$order->total()" />
                        </dd>
                    </div>

                    @if ($order->store_wallet_applied_minor > 0)
                        <div class="flex items-baseline justify-between gap-4 border-t border-slate-200 pt-3">
                            <dt class="text-base font-semibold text-slate-900">Payable (provider verified)</dt>
                            <dd class="text-xl font-bold tabular-nums text-slate-900">
                                <x-money :amount="$order->payable()" />
                            </dd>
                        </div>
                    @endif
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

            {{-- Fulfilment and delivery.

                 Kept visibly apart from the commercial cards above, so nobody
                 confuses moving a box with a statement about money. Nothing in
                 this card can mark an order paid, refund anything, move stock,
                 return a credit or change who won an auction. --}}
            @can('deliveries.view')
                <x-card title="Delivery" subtitle="The physical package. Separate from the money.">
                    @error('delivery')
                        <x-alert variant="danger" class="mb-4">{{ $message }}</x-alert>
                    @enderror

                    @if (! $delivery)
                        <p class="text-sm text-slate-600">
                            No delivery yet. One is opened when a payment is verified — an
                            unpaid, expired or blocked order has nothing to send.
                        </p>
                    @else
                        <div class="flex flex-wrap items-center gap-2">
                            <x-badge :classes="$delivery->status->badgeClasses()">
                                {{ $delivery->status->label() }}
                            </x-badge>
                            <span class="font-mono text-xs text-slate-500">{{ $delivery->reference }}</span>
                            @if ($delivery->attempts > 0)
                                <span class="text-xs text-slate-500">
                                    {{ $delivery->attempts }} {{ Str::plural('attempt', $delivery->attempts) }}
                                </span>
                            @endif
                        </div>

                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <dt class="text-slate-600">Delivering to</dt>
                                <dd class="mt-1 text-slate-900">
                                    @if ($delivery->hasAddress())
                                        <span class="font-semibold">{{ $delivery->recipient_name }}</span>
                                        · {{ $delivery->recipient_phone }}
                                        <span class="mt-1 block">{{ $delivery->addressSummary() }}</span>
                                        @if ($delivery->digital_address)
                                            <span class="block text-xs text-slate-500">{{ $delivery->digital_address }}</span>
                                        @endif
                                        @if ($delivery->landmark)
                                            <span class="block text-xs text-slate-500">Near {{ $delivery->landmark }}</span>
                                        @endif
                                        @if ($delivery->instructions)
                                            <span class="mt-1 block text-xs text-slate-600">
                                                Note from customer: {{ $delivery->instructions }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-amber-700">
                                            No address yet. The customer supplies this before anything
                                            can be packed.
                                        </span>
                                    @endif
                                </dd>
                            </div>

                            @if ($delivery->carrier || $delivery->tracking_reference)
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-600">Sent with</dt>
                                    <dd class="mt-1 text-slate-900">
                                        {{ $delivery->carrier ?? '—' }}
                                        @if ($delivery->tracking_reference)
                                            <span class="font-mono text-xs text-slate-500">
                                                {{ $delivery->tracking_reference }}
                                            </span>
                                        @endif
                                        <span class="mt-1 block text-xs text-slate-500">
                                            Recorded by hand. Not verifiable outside this platform.
                                        </span>
                                    </dd>
                                </div>
                            @endif

                            @if ($delivery->failure_reason)
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-600">Last failure</dt>
                                    <dd class="mt-1 text-red-800">
                                        {{ $delivery->failure_reason->label() }}
                                        @if ($delivery->failure_note)
                                            <span class="block text-xs">{{ $delivery->failure_note }}</span>
                                        @endif
                                        @if ($delivery->failure_reason->suggestsAddressReview())
                                            <span class="mt-1 block text-xs text-slate-600">
                                                Worth checking the address and phone number with the
                                                customer before trying again.
                                            </span>
                                        @endif
                                    </dd>
                                </div>
                            @endif

                            @if ($delivery->received_by)
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-600">Received by</dt>
                                    <dd class="mt-1 text-slate-900">{{ $delivery->received_by }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if (! $canFulfil)
                            <x-alert variant="warning" class="mt-4">
                                This order cannot be fulfilled in its current state, so the package
                                must not go out. Cancelling the delivery is the only move left.
                            </x-alert>
                        @endif

                        {{-- The controls. Each one is its own permission,
                             because in a warehouse these are different jobs. --}}
                        <div class="mt-5 space-y-4 border-t border-slate-100 pt-4">
                            <div class="grid gap-3 sm:grid-cols-3">
                                @if ($delivery->status === App\Enums\DeliveryStatus::Dispatched
                                     || $delivery->status === App\Enums\DeliveryStatus::ReadyForDispatch)
                                    <x-field label="Carrier or rider" name="carrier">
                                        <input type="text" wire:model="carrier" id="carrier"
                                               class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                    </x-field>

                                    <x-field label="Reference" name="trackingReference">
                                        <input type="text" wire:model="trackingReference" id="trackingReference"
                                               class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                    </x-field>
                                @endif

                                @if ($delivery->status === App\Enums\DeliveryStatus::Dispatched
                                     || $delivery->status === App\Enums\DeliveryStatus::OutForDelivery)
                                    <x-field label="Received by" name="receivedBy">
                                        <input type="text" wire:model="receivedBy" id="receivedBy"
                                               class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                    </x-field>
                                @endif

                                <x-field label="Note" name="deliveryNote" class="sm:col-span-3">
                                    <input type="text" wire:model="deliveryNote" id="deliveryNote" maxlength="500"
                                           class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                </x-field>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                @foreach ($delivery->status->allowedTransitions() as $next)
                                    @continue ($next === App\Enums\DeliveryStatus::DeliveryFailed)

                                    @if ($delivery->status === App\Enums\DeliveryStatus::DeliveryFailed
                                         && $next !== App\Enums\DeliveryStatus::Cancelled)
                                        @can('deliveries.retry')
                                            <x-button size="sm" variant="ghost"
                                                      wire:click="retryDelivery('{{ $next->value }}')">
                                                Retry — {{ $next->label() }}
                                            </x-button>
                                        @endcan
                                    @else
                                        <x-button size="sm"
                                                  :variant="$next === App\Enums\DeliveryStatus::Cancelled ? 'ghost' : 'primary'"
                                                  wire:click="moveDelivery('{{ $next->value }}')">
                                            {{ $next->label() }}
                                        </x-button>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Failure needs a reason, so it is a separate
                                 control rather than one more button. --}}
                            @if ($delivery->status->hasLeft() && ! $delivery->isDelivered())
                                <div class="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
                                    <div class="w-full sm:w-64">
                                        <x-field label="Delivery did not work because" name="failureReason">
                                            <select wire:model="failureReason" id="failureReason"
                                                    class="block w-full rounded-lg border-0 bg-white px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                                <option value="">Choose a reason</option>
                                                @foreach ($failureReasons as $reason)
                                                    <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                                                @endforeach
                                            </select>
                                        </x-field>
                                    </div>

                                    <x-button size="sm" variant="ghost" wire:click="failDelivery">
                                        Record a failed attempt
                                    </x-button>
                                </div>

                                <p class="text-xs text-slate-500">
                                    Recording a failure changes nothing financial. It refunds nothing,
                                    restores no stock, returns no credits and reopens no auction.
                                </p>
                            @endif
                        </div>

                        @if ($deliveryTransitions && $deliveryTransitions->isNotEmpty())
                            <div class="mt-6 border-t border-slate-100 pt-4">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Delivery history
                                </p>
                                <ul class="mt-3 space-y-2 text-sm">
                                    @foreach ($deliveryTransitions as $transition)
                                        <li class="flex flex-wrap justify-between gap-3">
                                            <span class="text-slate-700">
                                                {{ $transition->from_status?->label() ?? 'Opened' }}
                                                &rarr;
                                                <span class="font-semibold">{{ $transition->to_status->label() }}</span>
                                                @if ($transition->reason_code)
                                                    <span class="block text-xs text-red-700">
                                                        {{ $transition->reason_code->label() }}
                                                    </span>
                                                @endif
                                                @if ($transition->note)
                                                    <span class="block text-xs text-slate-500">{{ $transition->note }}</span>
                                                @endif
                                            </span>
                                            <span class="text-xs text-slate-500">
                                                {{ $transition->causedBy?->name ?? 'System' }}
                                                ·
                                                {{ $transition->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                </x-card>
            @endcan

            <x-card title="History" :padded="false">
                {{-- Four columns including a timestamp: wrapped so it scrolls
                     inside its card on a phone rather than pushing the page
                     sideways. The two tables above do the same. --}}
                <div class="overflow-x-auto">
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
                </div>
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
</x-admin.shell>

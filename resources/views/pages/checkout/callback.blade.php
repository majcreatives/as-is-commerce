{{-- Where the customer lands after paying.

     This page reports what the SERVER established, never what the browser
     assumed. Arriving here is not a payment; the controller asked Paystack
     directly before anything below was chosen.

     "Pending" is a real and common answer, not a failure: mobile money settles
     asynchronously, and a customer frequently arrives here before the payment
     has finished. Saying "paid" at that point would be a lie that a webhook
     would later have to make true. --}}

<x-layouts.app title="Payment">
    <div class="mx-auto max-w-xl">
        @if ($state === 'paid')
            <x-card title="Payment confirmed">
                <p class="text-sm text-slate-700">
                    We verified your payment with Paystack. Order
                    <strong>{{ $order->order_number }}</strong> is confirmed.
                </p>

                @if ($order->isFulfilmentBlocked())
                    <x-alert variant="warning" class="mt-4">
                        Your payment went through, but we could not complete this order — someone
                        else acquired the item while you were paying. Our team has it and will be
                        in touch.
                    </x-alert>
                @elseif ($order->endedAnAuction())
                    <x-alert variant="info" class="mt-4">
                        Buying this outright ended the auction. The credits other bidders had
                        committed stay consumed, as they always do.
                    </x-alert>
                @endif

                <div class="mt-5 flex flex-wrap gap-3">
                    <x-button href="{{ route('orders.show', $order) }}" wire:navigate>
                        View your order
                    </x-button>
                    <x-button variant="ghost" href="{{ route('orders.index') }}" wire:navigate>
                        All my orders
                    </x-button>
                </div>
            </x-card>
        @elseif ($state === 'processing')
            <x-card title="Confirming your payment">
                <p class="text-sm text-slate-700">
                    We are confirming this with Paystack right now. Give it a moment and refresh —
                    your order will update itself once we have an answer.
                </p>

                <x-button variant="secondary" class="mt-5"
                          href="{{ route('orders.show', $order) }}" wire:navigate>
                    Check your order
                </x-button>
            </x-card>
        @elseif ($state === 'pending')
            <x-card title="Payment not confirmed yet">
                <p class="text-sm text-slate-700">
                    Paystack has not confirmed this payment yet. If you paid by mobile money this
                    is normal — it can take a short while to settle.
                </p>

                <p class="mt-3 text-sm text-slate-600">
                    We will confirm it automatically as soon as Paystack tells us. Nothing is
                    charged twice by waiting, and your order below shows the current state.
                </p>

                <x-button variant="secondary" class="mt-5"
                          href="{{ route('orders.show', $order) }}" wire:navigate>
                    Check your order
                </x-button>
            </x-card>
        @else
            <x-card title="We could not find that payment">
                <p class="text-sm text-slate-700">
                    That payment reference does not match anything on your account. If you have
                    just paid, check your orders — it may already be there.
                </p>

                <x-button variant="secondary" class="mt-5"
                          href="{{ route('orders.index') }}" wire:navigate>
                    My orders
                </x-button>
            </x-card>
        @endif
    </div>
</x-layouts.app>

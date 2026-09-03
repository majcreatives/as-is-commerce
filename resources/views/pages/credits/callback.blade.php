<x-layouts.app title="Payment">
    <div class="mx-auto max-w-lg">
        @if ($state === 'fulfilled' && $purchase)
            <x-card>
                <div class="text-center">
                    <p class="text-sm font-medium text-emerald-700">Payment confirmed</p>

                    <p class="mt-2 text-3xl font-bold tabular-nums text-slate-900">
                        +{{ number_format($purchase->credit_amount) }}
                    </p>
                    <p class="text-sm text-slate-500">
                        {{ Str::plural('credit', $purchase->credit_amount) }} added to your wallet
                    </p>

                    <p class="mt-4 text-sm text-slate-600">
                        {{ $purchase->package_name_snapshot }} &middot;
                        {{ settings()->getString('currency_symbol', 'GH₵') }} {{ $purchase->amount()->format() }}
                    </p>

                    <div class="mt-6 flex flex-wrap justify-center gap-2">
                        <x-button href="{{ route('wallet') }}" wire:navigate variant="primary">View wallet</x-button>
                        <x-button href="{{ route('credits.packages') }}" wire:navigate variant="secondary">
                            Buy more credits
                        </x-button>
                    </div>
                </div>
            </x-card>

        @elseif ($state === 'processing' && $purchase)
            <x-card>
                <div class="text-center">
                    <p class="text-sm font-medium text-brand-800">Confirming your payment</p>
                    <p class="mt-2 text-sm text-slate-600">
                        Your payment is being confirmed right now. Your credits will appear in a moment.
                    </p>
                    <div class="mt-6">
                        <x-button href="{{ route('credits.history') }}" variant="secondary">Check status</x-button>
                    </div>
                </div>
            </x-card>

        @elseif ($state === 'pending' && $purchase)
            <x-card>
                <div class="text-center">
                    <p class="text-sm font-medium text-slate-700">Payment not confirmed yet</p>

                    {{-- Honest rather than optimistic. Mobile money settles
                         asynchronously, so arriving here before the payment
                         completes is normal and is not a failure. --}}
                    <p class="mt-2 text-sm text-slate-600">
                        We have not received confirmation from the payment provider yet. If you paid by
                        mobile money, this can take a short while. Your credits are added automatically
                        as soon as the payment is confirmed &mdash; there is no need to pay again.
                    </p>

                    <p class="mt-4 text-xs text-slate-500">
                        {{ $purchase->package_name_snapshot }} &middot;
                        {{ number_format($purchase->credit_amount) }} credits &middot;
                        <span class="font-mono">{{ $purchase->provider_reference }}</span>
                    </p>

                    <div class="mt-6 flex flex-wrap justify-center gap-2">
                        <x-button href="{{ route('credits.callback', ['reference' => $purchase->provider_reference]) }}"
                                  variant="primary">Check again</x-button>
                        <x-button href="{{ route('credits.history') }}" wire:navigate variant="secondary">
                            Purchase history
                        </x-button>
                    </div>
                </div>
            </x-card>

        @else
            <x-card>
                <div class="text-center">
                    <p class="text-sm font-medium text-slate-700">Payment not found</p>
                    <p class="mt-2 text-sm text-slate-600">
                        We could not find a payment matching that reference on your account.
                    </p>
                    <div class="mt-6">
                        <x-button href="{{ route('credits.history') }}" wire:navigate variant="secondary">
                            Purchase history
                        </x-button>
                    </div>
                </div>
            </x-card>
        @endif
    </div>
</x-layouts.app>

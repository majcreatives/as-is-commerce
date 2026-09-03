<div>
    <x-page-header
        title="Buy credits"
        description="Credits are what you bid with. They are not money, and they are spent when you bid." />

    <x-card class="mb-6">
        <div class="flex items-baseline justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-slate-500">Your credit balance</p>
                <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                    {{ number_format($creditBalance) }}
                </p>
            </div>

            <x-button href="{{ route('credits.history') }}" wire:navigate variant="secondary" size="sm">
                Purchase history
            </x-button>
        </div>
    </x-card>

    @error('package')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    @if ($packages->isEmpty())
        <x-empty-state
            title="No credit packages are on sale"
            description="Credit packages will appear here once they are available." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($packages as $package)
                <x-card class="flex flex-col">
                    <p class="text-sm font-medium text-slate-500">{{ $package->name }}</p>

                    {{-- Credits and price are shown as two separate facts. They
                         are never equated: 500 credits is not GH₵500. --}}
                    <p class="mt-2 text-3xl font-bold tabular-nums text-slate-900">
                        {{ number_format($package->credit_amount) }}
                    </p>
                    <p class="text-sm text-slate-500">
                        {{ Str::plural('credit', $package->credit_amount) }}
                    </p>

                    <p class="mt-4 text-xl font-semibold tabular-nums text-brand-800">
                        {{ settings()->getString('currency_symbol', 'GH₵') }} {{ $package->price()->format() }}
                    </p>

                    @if ($package->description)
                        <p class="mt-2 flex-1 text-sm text-slate-600">{{ $package->description }}</p>
                    @else
                        <div class="flex-1"></div>
                    @endif

                    <div class="mt-5">
                        <x-button
                            wire:click="purchase('{{ $package->slug }}')"
                            wire:loading.attr="disabled"
                            wire:target="purchase('{{ $package->slug }}')"
                            variant="primary"
                            class="w-full">
                            <span wire:loading.remove wire:target="purchase('{{ $package->slug }}')">
                                Buy credits
                            </span>
                            <span wire:loading wire:target="purchase('{{ $package->slug }}')">
                                Opening payment&hellip;
                            </span>
                        </x-button>
                    </div>
                </x-card>
            @endforeach
        </div>

        <x-alert variant="info" class="mt-6">
            <strong class="font-semibold">Credits are spent when you bid.</strong>
            They are consumed whether or not you go on to win, and they cannot be converted
            back into money. Payment is taken securely by Paystack; your credits are added
            once the payment is confirmed, which can take a moment with mobile money.
        </x-alert>
    @endif
</div>

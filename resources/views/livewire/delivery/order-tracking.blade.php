{{-- Where a customer's package has got to.

     Only steps that actually happened are marked done. A future step is never
     shown as complete and never given a date — a tracking page that guesses is
     worse than one that says little.

     Nothing internal appears here: no staff notes, no raw failure code, no
     audit trail, no carrier reference that is meaningless outside our own
     records. --}}

<div>
    <x-page-header
        :title="'Tracking '.$order->order_number"
        :description="$order->item()?->product_name_snapshot" />

    <div class="mb-6 flex flex-wrap items-center gap-2">
        <x-badge :classes="$order->status->badgeClasses()">{{ $order->status->label() }}</x-badge>

        @if ($delivery)
            <x-badge :classes="$delivery->status->badgeClasses()">
                {{ $delivery->status->customerLabel() }}
            </x-badge>
        @endif
    </div>

    @if (session('tracking'))
        <x-alert variant="success" class="mb-6">{{ session('tracking') }}</x-alert>
    @endif

    @if (! $delivery)
        <x-empty-state
            title="Nothing to track yet"
            description="Once your payment is confirmed we will start preparing your order, and this page will show you where it has got to." />
    @else
        {{-- The auction winner's path. Their order was created the moment the
             auction closed, when nobody was at a keyboard to be asked where it
             should go. --}}
        @if ($delivery->isAwaitingAddress())
            <x-card title="Where should we send this?" class="mb-6">
                <p class="text-sm text-slate-600">
                    We have your payment and your order is waiting on a delivery address.
                </p>

                @error('address')
                    <x-alert variant="danger" class="mt-4">{{ $message }}</x-alert>
                @enderror

                @if ($addresses->isEmpty())
                    <p class="mt-4 text-sm text-slate-600">
                        You have no saved addresses yet.
                    </p>
                    <x-button class="mt-3" href="{{ route('addresses.index') }}" wire:navigate>
                        Add a delivery address
                    </x-button>
                @else
                    <form wire:submit="chooseAddress" class="mt-4 space-y-4">
                        <x-field label="Deliver to" name="selectedAddressId">
                            <select wire:model="selectedAddressId" id="selectedAddressId"
                                    class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                                <option value="">Choose an address</option>
                                @foreach ($addresses as $address)
                                    <option value="{{ $address->id }}">
                                        {{ $address->displayLabel() }} — {{ $address->summary() }}
                                    </option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-button type="submit">Send it here</x-button>
                    </form>
                @endif
            </x-card>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-card title="Progress">
                    <ol class="space-y-5">
                        @foreach ($steps as $step)
                            <li class="flex gap-4">
                                <div class="flex flex-col items-center">
                                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full
                                                 {{ $step['reached'] ? 'bg-brand-700 text-white' : 'bg-slate-200 text-slate-400' }}">
                                        @if ($step['reached'])
                                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
                                            </svg>
                                        @endif
                                    </span>
                                    @unless ($loop->last)
                                        <span class="mt-1 w-px flex-1 {{ $step['reached'] ? 'bg-brand-200' : 'bg-slate-200' }}"></span>
                                    @endunless
                                </div>

                                <div class="pb-1">
                                    <p class="font-semibold {{ $step['reached'] ? 'text-slate-900' : 'text-slate-400' }}">
                                        {{ $step['status']->customerLabel() }}
                                    </p>
                                    {{-- A time only where something happened.
                                         A future step gets no date. --}}
                                    @if ($step['at'])
                                        <p class="text-xs text-slate-500">
                                            {{ $step['at']->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                                        </p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @if ($delivery->hasFailed())
                        <x-alert variant="warning" class="mt-6">
                            <p class="font-semibold">We could not complete the delivery.</p>
                            <p class="mt-1">
                                We tried to deliver your order and
                                {{ $delivery->failure_reason?->customerDescription() ?? 'could not complete it' }}.
                                Our team will be in touch to arrange another attempt.
                            </p>
                        </x-alert>
                    @endif

                    @if ($delivery->status === App\Enums\DeliveryStatus::Cancelled)
                        <x-alert variant="warning" class="mt-6">
                            This delivery was cancelled. Our team will be in touch about your order.
                        </x-alert>
                    @endif
                </x-card>
            </div>

            <div class="space-y-6">
                <x-card title="Delivering to">
                    @if ($delivery->hasAddress())
                        <p class="font-semibold text-slate-900">{{ $delivery->recipient_name }}</p>
                        <p class="text-sm text-slate-600">{{ $delivery->recipient_phone }}</p>
                        <p class="mt-2 text-sm text-slate-600">{{ $delivery->addressSummary() }}</p>
                        @if ($delivery->digital_address)
                            <p class="mt-1 text-xs text-slate-500">{{ $delivery->digital_address }}</p>
                        @endif
                        @if ($delivery->landmark)
                            <p class="mt-1 text-xs text-slate-500">Near {{ $delivery->landmark }}</p>
                        @endif

                        @if (! $delivery->addressIsEditable())
                            <p class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                                We are already preparing this order, so its address is fixed.
                            </p>
                        @endif
                    @else
                        <p class="text-sm text-slate-600">No delivery address yet.</p>
                    @endif
                </x-card>

                <x-card title="Reference">
                    <p class="font-mono text-sm text-slate-900">{{ $delivery->reference }}</p>
                    {{-- Said plainly: this is ours. There is no courier
                         integration, so quoting it anywhere else would get the
                         customer nowhere. --}}
                    <p class="mt-2 text-xs text-slate-500">
                        Our own reference for this delivery. Quote it if you contact us.
                    </p>

                    @if ($delivery->delivered_at)
                        <p class="mt-4 border-t border-slate-100 pt-3 text-sm text-slate-700">
                            Delivered
                            {{ $delivery->delivered_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y') }}
                            @if ($delivery->received_by)
                                to {{ $delivery->received_by }}
                            @endif
                        </p>
                    @endif
                </x-card>

                <x-button variant="ghost" href="{{ route('orders.show', $order) }}" wire:navigate>
                    Back to the order
                </x-button>
            </div>
        </div>
    @endif
</div>

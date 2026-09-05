{{-- A customer's own delivery addresses.

     Editing one changes where the next package goes and nothing that has
     already shipped: a delivery holds its own frozen copy. The page says so,
     because somebody correcting a typo has a fair right to wonder. --}}

<div>
    <x-page-header
        title="Delivery addresses"
        description="Where we send your orders. You can save more than one." />

    @if (session('addresses'))
        <x-alert variant="success" class="mb-6">{{ session('addresses') }}</x-alert>
    @endif

    <x-alert variant="info" class="mb-6">
        Changing an address here affects your next order only. Anything already on its way keeps
        the address it was sent to.
    </x-alert>

    @if (! $showingForm)
        <x-button class="mb-6" wire:click="startAdding">Add an address</x-button>
    @endif

    @if ($showingForm)
        <x-card :title="$editingId ? 'Edit address' : 'Add an address'" class="mb-6">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <x-field label="Name for this address" name="form.label" class="sm:col-span-2">
                    <input type="text" wire:model="form.label" id="form.label" placeholder="Home"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <x-field label="Who receives it" name="form.recipient_name" required>
                    <input type="text" wire:model="form.recipient_name" id="form.recipient_name"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <x-field label="Phone number" name="form.recipient_phone" required>
                    <input type="tel" wire:model="form.recipient_phone" id="form.recipient_phone"
                           placeholder="024 123 4567"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <x-field label="Address" name="form.address_line" required class="sm:col-span-2">
                    <input type="text" wire:model="form.address_line" id="form.address_line"
                           placeholder="House number and street, or a description"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <x-field label="Area" name="form.area">
                    <input type="text" wire:model="form.area" id="form.area" placeholder="East Legon"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <x-field label="City or town" name="form.city" required>
                    <input type="text" wire:model="form.city" id="form.city" placeholder="Accra"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <x-field label="Region" name="form.region">
                    <input type="text" wire:model="form.region" id="form.region" placeholder="Greater Accra"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                {{-- Optional, deliberately. Most people do not know their
                     GhanaPostGPS code, and requiring it would block the
                     checkout of everybody who does not. --}}
                <x-field label="GhanaPostGPS (optional)" name="form.digital_address">
                    <input type="text" wire:model="form.digital_address" id="form.digital_address"
                           placeholder="GA-123-4567"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <x-field label="Landmark (optional)" name="form.landmark">
                    <input type="text" wire:model="form.landmark" id="form.landmark"
                           placeholder="Opposite the filling station"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <x-field label="Anything the rider should know" name="form.instructions" class="sm:col-span-2">
                    <input type="text" wire:model="form.instructions" id="form.instructions"
                           class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
                </x-field>

                <div class="flex gap-3 sm:col-span-2">
                    <x-button type="submit">Save address</x-button>
                    <x-button type="button" variant="ghost" wire:click="cancel">Cancel</x-button>
                </div>
            </form>
        </x-card>
    @endif

    @if ($addresses->isEmpty())
        <x-empty-state
            title="No addresses yet"
            description="Add one and we will know where to send your orders." />
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($addresses as $address)
                <x-card>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900">
                                {{ $address->displayLabel() }}
                                @if ($address->is_default)
                                    <x-badge classes="bg-brand-50 text-brand-800 ring-brand-200">Default</x-badge>
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-slate-700">{{ $address->recipient_name }}</p>
                            <p class="text-sm text-slate-600">{{ $address->recipient_phone }}</p>
                            <p class="mt-2 text-sm text-slate-600">{{ $address->summary() }}</p>
                            @if ($address->digital_address)
                                <p class="mt-1 text-xs text-slate-500">{{ $address->digital_address }}</p>
                            @endif
                            @if ($address->landmark)
                                <p class="mt-1 text-xs text-slate-500">Near {{ $address->landmark }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                        <x-button size="sm" variant="ghost" wire:click="startEditing({{ $address->id }})">
                            Edit
                        </x-button>

                        @unless ($address->is_default)
                            <x-button size="sm" variant="ghost" wire:click="makeDefault({{ $address->id }})">
                                Make default
                            </x-button>
                            <x-button size="sm" variant="ghost" wire:click="delete({{ $address->id }})">
                                Delete
                            </x-button>
                        @endunless
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</div>

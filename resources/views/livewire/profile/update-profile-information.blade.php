<div>
    <x-card title="Account details" subtitle="Your name, phone number and email address.">
        <div x-data="{ saved: false }"
             x-on:profile-updated.window="saved = true; setTimeout(() => saved = false, 3000)">

            <div x-show="saved" x-cloak class="mb-4">
                <x-alert variant="success">Your details have been saved.</x-alert>
            </div>

            <form wire:submit="save" class="space-y-4">
                <x-field label="Full name" name="name" :error="$errors->first('name')" optional>
                    <x-input id="name" autocomplete="name" wire:model="name" :error="$errors->has('name')" />
                </x-field>

                <x-field label="Phone number" name="phone" :error="$errors->first('phone')"
                         hint="Changing your phone number will require verifying the new number before you can bid.">
                    <x-input id="phone" type="tel" inputmode="tel" autocomplete="tel"
                             wire:model="phone" :error="$errors->has('phone')" required />
                </x-field>

                <x-field label="Email address" name="email" :error="$errors->first('email')" optional
                         hint="Changing your email address will require verifying the new address.">
                    <x-input id="email" type="email" autocomplete="email"
                             wire:model="email" :error="$errors->has('email')" />
                </x-field>

                <div class="flex justify-end">
                    <x-button type="submit" variant="primary" wire:loading.attr="disabled">Save changes</x-button>
                </div>
            </form>
        </div>
    </x-card>
</div>

<div>
    <x-card title="Password" subtitle="Use a password you do not reuse anywhere else.">
        <div x-data="{ saved: false }"
             x-on:password-updated.window="saved = true; setTimeout(() => saved = false, 3000)">

            <div x-show="saved" x-cloak class="mb-4">
                <x-alert variant="success">Your password has been updated.</x-alert>
            </div>

            <form wire:submit="save" class="space-y-4">
                <x-field label="Current password" name="current_password" :error="$errors->first('current_password')">
                    <x-input id="current_password" type="password" autocomplete="current-password"
                             wire:model="current_password" :error="$errors->has('current_password')" required />
                </x-field>

                <x-field label="New password" name="password" :error="$errors->first('password')">
                    <x-input id="password" type="password" autocomplete="new-password"
                             wire:model="password" :error="$errors->has('password')" required />
                </x-field>

                <x-field label="Confirm new password" name="password_confirmation">
                    <x-input id="password_confirmation" type="password" autocomplete="new-password"
                             wire:model="password_confirmation" required />
                </x-field>

                <div class="flex justify-end">
                    <x-button type="submit" variant="primary" wire:loading.attr="disabled">Update password</x-button>
                </div>
            </form>
        </div>
    </x-card>
</div>

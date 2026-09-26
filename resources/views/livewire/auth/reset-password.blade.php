{{-- Reset-password screen (guest).

     The code here is verified by VerifyOtp before the password is ever
     touched, so resetting happens only after proof that the customer receives
     messages at the address on the account -- by email or by text. --}}

<div>
    <x-card>
        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Reset your password</h1>
            <p class="mt-1 text-sm text-slate-600">
                Enter the code we sent by {{ $channel === 'sms' ? 'text message' : 'email' }} to
                <span class="font-medium">{{ $destinationHint }}</span>, then choose a new
                password.
            </p>
        </div>

        <form wire:submit="save" class="space-y-4">
            <x-field label="One-time code" name="code" :error="$errors->first('code')">
                <x-input
                    id="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    wire:model="code"
                    :error="$errors->has('code')"
                    placeholder="000000"
                    required
                    autofocus
                />
            </x-field>

            <x-field label="New password" name="password" :error="$errors->first('password')">
                <x-input
                    id="password"
                    type="password"
                    autocomplete="new-password"
                    wire:model="password"
                    :error="$errors->has('password')"
                    required
                />
            </x-field>

            <x-field label="Confirm new password" name="password_confirmation" :error="$errors->first('password_confirmation')">
                <x-input
                    id="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    wire:model="password_confirmation"
                    :error="$errors->has('password_confirmation')"
                    required
                />
            </x-field>

            <x-button
                type="submit"
                variant="primary"
                size="lg"
                class="w-full"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="save">Reset password</span>
                <span wire:loading wire:target="save">Resetting&hellip;</span>
            </x-button>
        </form>
    </x-card>

    <p class="mt-6 text-center text-sm text-slate-600">
        Remembered it now?
        <a href="{{ route('login') }}" wire:navigate class="font-semibold text-brand-700 hover:text-brand-800">
            Sign in
        </a>
    </p>
</div>
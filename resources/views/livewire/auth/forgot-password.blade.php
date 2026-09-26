{{-- Forgot-password screen (guest).

     Accepts the email address or the phone number on the account -- email is
     optional at registration, so a phone-only customer has no other way back
     in. Only a value that actually matches an account reaches SendOtp, and
     answers identically otherwise, so the page can never be used to probe
     which addresses or numbers exist. --}}

<div>
    <x-card>
        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Forgot your password?</h1>
            <p class="mt-1 text-sm text-slate-600">
                Enter the email address or phone number on your account and
                we'll send you a one-time code.
            </p>
        </div>

        @if (session('status'))
            <div class="mb-4">
                <x-alert variant="info">{{ session('status') }}</x-alert>
            </div>
        @endif

        <form wire:submit="send" class="space-y-4">
            <x-field label="Email address or phone number" name="identifier" :error="$errors->first('identifier')">
                <x-input
                    id="identifier"
                    type="text"
                    inputmode="text"
                    autocomplete="username"
                    wire:model="identifier"
                    :error="$errors->has('identifier')"
                    required
                    autofocus
                />
            </x-field>

            <x-button
                type="submit"
                variant="primary"
                size="lg"
                class="w-full"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove wire:target="send">Send code</span>
                <span wire:loading wire:target="send">Sending&hellip;</span>
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
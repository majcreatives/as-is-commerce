{{-- Forgot-password screen (guest).

     Only reaches SendOtp for an address that actually matches an account, and
     answers identically otherwise, so the page can never be used to probe
     which addresses exist. --}}

<div>
    <x-card>
        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Forgot your password?</h1>
            <p class="mt-1 text-sm text-slate-600">
                Enter the email address on your account and we'll send you a
                one-time code.
            </p>
        </div>

        @if (session('status'))
            <div class="mb-4">
                <x-alert variant="info">{{ session('status') }}</x-alert>
            </div>
        @endif

        <form wire:submit="send" class="space-y-4">
            <x-field label="Email address" name="email" :error="$errors->first('email')">
                <x-input
                    id="email"
                    type="email"
                    autocomplete="email"
                    wire:model="email"
                    :error="$errors->has('email')"
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
{{-- Email verification card for the customer profile.

     The card is presentation only: codes are issued and verified by SendOtp /
     VerifyOtp on the server, and the "verified" state shown here is the
     account's own email_verified_at timestamp, never a client-side claim. --}}

<div>
    <x-card title="Email verification" subtitle="Confirm you can receive mail at this address.">
        @if ($verified)
            <div>
                <x-alert variant="success">Your email address is verified.</x-alert>
            </div>
        @else
            @if (Auth::user()->email)
                <div class="mb-4">
                    <x-alert variant="info">
                        We'll send a one-time code to
                        <span class="font-medium">{{ Auth::user()->email }}</span>.
                    </x-alert>
                </div>
            @else
                <div class="mb-4">
                    <x-alert variant="warning">
                        You don't have an email address on your account yet. Add one
                        above so we can send you a code.
                    </x-alert>
                </div>
            @endif

            @if ($sent)
                <div class="mb-4">
                    <x-alert variant="success">
                        A code is on its way — check your inbox and spam folder.
                    </x-alert>
                </div>
            @endif

            <form wire:submit="verify" class="space-y-4">
                <x-field label="One-time code" name="code" :error="$errors->first('code')">
                    <x-input
                        id="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        wire:model="code"
                        :error="$errors->has('code')"
                        placeholder="000000"
                        required
                    />
                </x-field>

                <div class="flex items-center justify-between gap-3">
                    <button
                        type="button"
                        wire:click="send"
                        class="text-sm font-semibold text-brand-700 hover:text-brand-800"
                    >
                        {{ $sent ? 'Resend code' : 'Send code' }}
                    </button>

                    <x-button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                    >
                        Verify
                    </x-button>
                </div>
            </form>

            <p class="mt-4 text-xs text-slate-500">
                Why verify? If you ever forget your password, a code sent to this
                address lets you set a new one.
            </p>
        @endif
    </x-card>
</div>
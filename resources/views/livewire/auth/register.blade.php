<div>
    <x-card>
        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Create your account</h1>
            <p class="mt-1 text-sm text-slate-600">
                Your phone number is your sign-in ID. Email is optional.
            </p>
        </div>

        <form wire:submit="register" class="space-y-4">
            <x-field label="Phone number" name="phone" :error="$errors->first('phone')"
                     hint="Ghanaian mobile number, for example 024 412 3456.">
                <x-input id="phone" type="tel" inputmode="tel" autocomplete="tel"
                         wire:model="phone" :error="$errors->has('phone')"
                         placeholder="024 412 3456" required autofocus />
            </x-field>

            <x-field label="Full name" name="name" :error="$errors->first('name')" optional>
                <x-input id="name" autocomplete="name" wire:model="name" :error="$errors->has('name')" />
            </x-field>

            <x-field label="Email address" name="email" :error="$errors->first('email')" optional
                     hint="Add an email if you want to be able to reset your password yourself.">
                <x-input id="email" type="email" autocomplete="email"
                         wire:model="email" :error="$errors->has('email')" />
            </x-field>

            <x-field label="Password" name="password" :error="$errors->first('password')">
                <x-input id="password" type="password" autocomplete="new-password"
                         wire:model="password" :error="$errors->has('password')" required />
            </x-field>

            <x-field label="Confirm password" name="password_confirmation">
                <x-input id="password_confirmation" type="password" autocomplete="new-password"
                         wire:model="password_confirmation" required />
            </x-field>

            <x-button type="submit" variant="primary" size="lg" class="w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="register">Create account</span>
                <span wire:loading wire:target="register">Creating account&hellip;</span>
            </x-button>
        </form>
    </x-card>

    <p class="mt-6 text-center text-sm text-slate-600">
        Already have an account?
        <a href="{{ route('login') }}" wire:navigate class="font-semibold text-brand-700 hover:text-brand-800">Sign in</a>
    </p>
</div>

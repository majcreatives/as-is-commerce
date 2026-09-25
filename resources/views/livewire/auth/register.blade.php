<div class="min-h-screen lg:grid lg:grid-cols-2">
    {{-- Marketing hero. Desktop only: a single full-bleed image with no copy --
         this column simply does not exist below lg, where the register page is a
         clean single white column. The artwork is decorative (empty alt, never
         carries text or claims). Swap the file at public/images/register-hero.svg
         -- same filename keeps a deployed site working; change the asset() src
         if you use a different name. When the file is replaced, bump the ?v=
         on the src so the Hostinger edge cache serves the new bytes. --}}
    <div class="relative hidden overflow-hidden bg-slate-900 lg:block">
        <img src="{{ asset('images/register-hero.svg') }}?v=2" alt=""
             class="absolute inset-0 h-full w-full object-cover" >
    </div>

    {{-- Registration form. --}}
    <div class="flex min-h-screen flex-col bg-white px-4 py-10 sm:px-6">
        <div class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center">
            <a href="{{ route('home') }}" class="mb-8 flex justify-center">
                <x-logo />
            </a>

            @if (session('status'))
                <x-alert variant="info" class="mb-6">{{ session('status') }}</x-alert>
            @endif

            <div class="mb-6 text-center">
                <h1 class="text-2xl font-bold tracking-tight text-slate-900">Create your account</h1>
                <p class="mt-2 text-sm text-slate-600">Your phone number is your sign-in ID. Email is optional.</p>
            </div>

            <form wire:submit="register" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="First name" name="first_name" :error="$errors->first('first_name')" optional>
                        <x-input id="first_name" autocomplete="given-name"
                                 wire:model="first_name" :error="$errors->has('first_name')"
                                 placeholder="Ada" />
                    </x-field>

                    <x-field label="Last name" name="last_name" :error="$errors->first('last_name')" optional>
                        <x-input id="last_name" autocomplete="family-name"
                                 wire:model="last_name" :error="$errors->has('last_name')"
                                 placeholder="Lovelace" />
                    </x-field>
                </div>

                <x-field label="Phone number" name="phone" :error="$errors->first('phone')"
                         hint="A Ghanaian mobile number, for example 024 412 3456. This is the number you will sign in with.">
                    <x-input id="phone" type="tel" inputmode="tel" autocomplete="tel"
                             wire:model="phone" :error="$errors->has('phone')"
                             placeholder="024 412 3456" required autofocus />
                </x-field>

                <x-field label="Email address" name="email" :error="$errors->first('email')" optional
                         hint="Add an email if you want to be able to reset your password yourself.">
                    <x-input id="email" type="email" autocomplete="email"
                             wire:model="email" :error="$errors->has('email')" />
                </x-field>

                <x-field label="Password" name="password" :error="$errors->first('password')">
                    <x-password-input id="password" autocomplete="new-password"
                                      wire:model="password" :error="$errors->has('password')" required />
                </x-field>

                <x-field label="Confirm password" name="password_confirmation"
                         :error="$errors->first('password_confirmation')">
                    <x-password-input id="password_confirmation" autocomplete="new-password"
                                      wire:model="password_confirmation" required />
                </x-field>

                <x-button type="submit" variant="primary" size="lg" class="w-full" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="register">Create account</span>
                    <span wire:loading wire:target="register">Creating account&hellip;</span>
                </x-button>
            </form>

            <p class="mt-6 text-center text-sm text-slate-600">
                Already have an account?
                <a href="{{ route('login') }}" wire:navigate class="font-semibold text-brand-700 hover:text-brand-800">Sign in</a>
            </p>
        </div>

        <footer class="mt-8 text-center text-xs text-slate-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}
        </footer>
    </div>
</div>
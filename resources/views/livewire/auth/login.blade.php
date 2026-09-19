<div>
    <x-card>
        <div class="mb-6">
            <h1 class="text-xl font-bold tracking-tight text-slate-900">Sign in</h1>
            <p class="mt-1 text-sm text-slate-600">
                Use your phone number, or your email address if you added one.
            </p>
        </div>

        <form wire:submit="login" class="space-y-4">
            <x-field label="Phone number or email" name="identifier" :error="$errors->first('identifier')">
                <x-input id="identifier" autocomplete="username"
                         wire:model="identifier" :error="$errors->has('identifier')"
                         placeholder="024 412 3456" required autofocus />
            </x-field>

            <x-field label="Password" name="password" :error="$errors->first('password')">
                <x-input id="password" type="password" autocomplete="current-password"
                         wire:model="password" :error="$errors->has('password')" required />
            </x-field>

            <div class="-mt-2 flex justify-end">
                <a href="{{ route('password.request') }}" wire:navigate
                   class="text-sm font-semibold text-brand-700 hover:text-brand-800">
                    Forgot your password?
                </a>
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" wire:model="remember"
                       class="size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                Keep me signed in
            </label>

            <x-button type="submit" variant="primary" size="lg" class="w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Sign in</span>
                <span wire:loading wire:target="login">Signing in&hellip;</span>
            </x-button>
        </form>
    </x-card>

    <p class="mt-6 text-center text-sm text-slate-600">
        New to {{ config('app.name') }}?
        <a href="{{ route('register') }}" wire:navigate class="font-semibold text-brand-700 hover:text-brand-800">Create an account</a>
    </p>
</div>

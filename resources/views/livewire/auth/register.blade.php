<div class="min-h-screen lg:grid lg:grid-cols-2">
    {{-- Marketing hero. Desktop only: this column simply does not exist below
         lg, where the register page is a clean single white column. The copy
         only states what the platform demonstrably is -- a shop in cedis, some
         products auctioned for credits, prices in GH&#8373; -- and never promises
         earnings or a reward. --}}
    <div class="relative hidden overflow-hidden bg-slate-900 lg:flex lg:flex-col lg:justify-center lg:px-12 lg:py-16">
        <div class="absolute inset-0" aria-hidden="true">
            <div class="absolute -left-24 -top-24 size-96 rounded-full bg-brand-600/40 blur-3xl"></div>
            <div class="absolute -bottom-32 -right-16 size-[28rem] rounded-full bg-brand-400/25 blur-3xl"></div>
            <svg viewBox="0 0 600 800" preserveAspectRatio="xMidYMid slice" class="absolute inset-0 h-full w-full">
                <g fill="none" stroke="#6d8bfb" stroke-width="1" opacity="0.35">
                    <path d="M-40 560 L240 120 L520 560 Z" />
                    <path d="M520 240 L640 240 L640 120 L520 120 Z" />
                    <path d="M240 800 L520 400 L600 480 L320 800 Z" />
                </g>
                <g fill="#6d8bfb" opacity="0.28">
                    <circle cx="500" cy="120" r="10" />
                    <circle cx="60" cy="360" r="14" />
                    <circle cx="120" cy="700" r="8" />
                    <circle cx="560" cy="620" r="6" />
                </g>
                <g fill="#fbbf24" opacity="0.45">
                    <rect x="470" y="180" width="14" height="14" transform="rotate(45 477 187)" />
                    <rect x="380" y="120" width="10" height="10" transform="rotate(45 385 125)" />
                </g>
                <g stroke="#fbbf24" stroke-width="1.5" opacity="0.5">
                    <path d="M240 120 l24 16 M264 136 l-24 16 M240 120 l-24 16 M216 136 l24-16" />
                </g>
            </svg>
        </div>

        <div class="relative z-10 max-w-xl">
            <p class="text-sm font-semibold uppercase tracking-widest text-brand-300">Welcome to As-Is-Commerce</p>
            <h1 class="mt-4 text-4xl font-bold tracking-tight text-white">
                Shop in cedis.<br>Win with credits.
            </h1>
            <p class="mt-4 text-lg text-slate-300">
                Every product has a Buy Now price in GH&#8373;, and most are yours the
                moment you pay. Some are auctioned too.
            </p>

            <ul class="mt-8 space-y-4 text-sm text-slate-300">
                <li class="flex gap-3">
                    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-500/30 text-brand-200">&#10003;</span>
                    <span>
                        Everything is a published price in cedis, so a credit balance
                        is never quietly turned into cash.
                    </span>
                </li>
                <li class="flex gap-3">
                    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-500/30 text-brand-200">&#10003;</span>
                    <span>
                        Auctions are bid with credits, and the largest total of credits
                        committed wins when an auction closes.
                    </span>
                </li>
                <li class="flex gap-3">
                    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-500/30 text-brand-200">&#10003;</span>
                    <span>
                        Payments are verified with the provider before an order moves
                        on, so a paid order is a paid order.
                    </span>
                </li>
            </ul>

            <p class="mt-8 text-sm text-slate-400">
                Built for Ghana. Prices are in cedis and your phone number is your sign-in ID.
            </p>

            <a href="{{ route('how-it-works') }}" wire:navigate
               class="mt-6 inline-block text-sm font-semibold text-brand-300 hover:text-brand-200">
                How credits and bidding work&rarr;
            </a>
        </div>
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
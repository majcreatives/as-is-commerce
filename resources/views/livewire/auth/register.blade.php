<div>
    {{-- Two columns on a desk or tablet, one on a phone: the form is the thing,
         the "what you get" panel is on the side. The panel only says what the
         platform demonstrably is -- a shop in cedis, auctions bid with credits,
         Ghana/GH₵ -- and never promises earnings or a reward. --}}
    <div class="md:grid md:grid-cols-5 md:gap-8">
        <div class="md:col-span-3">
            <x-card>
                <div class="mb-6">
                    <h1 class="text-xl font-bold tracking-tight text-slate-900">Create your account</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Your phone number is your sign-in ID. Email is optional.
                    </p>
                </div>

                <form wire:submit="register" class="space-y-4">
                    <x-field label="Phone number" name="phone" :error="$errors->first('phone')"
                             hint="A Ghanaian mobile number, for example 024 412 3456. This is the number you will sign in with.">
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

                    {{-- The consent line is deferred with the 31.3 legal pages, which are not
                         built yet: a link to a page that does not exist is worse than none. --}}
                </form>
            </x-card>

            <p class="mt-6 text-center text-sm text-slate-600">
                Already have an account?
                <a href="{{ route('login') }}" wire:navigate class="font-semibold text-brand-700 hover:text-brand-800">Sign in</a>
            </p>
        </div>

        <aside class="mt-8 md:col-span-2 md:mt-0">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">What you get</h2>
                <p class="mt-1 text-sm text-slate-600">
                    A shop in cedis, with auctions on some products.
                </p>

                <ul class="mt-5 space-y-4 text-sm text-slate-600">
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-800">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                            </svg>
                        </span>
                        <span>
                            Every product has a Buy Now price in GH&#8373;,
                            and most are yours the moment you pay.
                        </span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-800">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972" />
                            </svg>
                        </span>
                        <span>
                            Some products are auctioned too. You bid with credits,
                            and the largest total of credits committed wins when an auction closes.
                        </span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-100 text-brand-800">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                            </svg>
                        </span>
                        <span>
                            Built for Ghana. Prices are in cedis, payments are verified
                            before an order moves on, and a credit balance is not cash.
                        </span>
                    </li>
                </ul>

                <a href="{{ route('how-it-works') }}" wire:navigate
                   class="mt-6 inline-block text-sm font-semibold text-brand-700 hover:text-brand-800">
                    How credits and bidding work&rarr;
                </a>
            </div>
        </aside>
    </div>
</div>
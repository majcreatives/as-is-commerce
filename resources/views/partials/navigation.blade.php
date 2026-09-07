@php
    // Whether the signed-in customer is somewhere inside their own account.
    // Used to light the menu trigger, so the header still shows where you are
    // when the page you are on lives behind a closed menu.
    $inAccount = request()->routeIs('dashboard', 'wallet', 'orders.*', 'addresses.*', 'referrals.*', 'profile.*', 'credits.history');

    // Everything about managing an account, in the order somebody looks for it:
    // what is happening, what it costs, what was bought, where it goes, then
    // the settings nobody visits twice.
    $accountLinks = [
        ['Dashboard', 'dashboard', 'dashboard'],
        ['Wallet', 'wallet', 'wallet'],
        ['Credit history', 'credits.history', 'credits.history'],
        ['Orders', 'orders.index', 'orders.*'],
        ['Addresses', 'addresses.index', 'addresses.*'],
        ['Invite friends', 'referrals.index', 'referrals.*'],
        ['Profile', 'profile.edit', 'profile.*'],
    ];
@endphp

<header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
    <x-container class="flex h-16 items-center justify-between">
        <div class="flex items-center gap-8">
            <a href="{{ route('home') }}" aria-label="{{ config('app.name') }} home">
                <x-logo />
            </a>

            {{-- WHAT EARNS A TOP-LEVEL SLOT. Places to browse, the page that
                 explains the model, and the two things a bidder needs at a
                 glance: credits to spend and whether anything happened.
                 Everything about managing an account sits in the menu on the
                 right, because a signed-in header of a dozen links wraps on a
                 laptop and reads as a list rather than a choice. --}}
            <nav class="hidden items-center gap-1 md:flex" aria-label="Primary">
                <x-nav-link href="{{ route('home') }}" :active="request()->routeIs('home')">Home</x-nav-link>
                <x-nav-link href="{{ route('products.index') }}" :active="request()->routeIs('products.*')">Shop</x-nav-link>
                <x-nav-link href="{{ route('auctions.index') }}" :active="request()->routeIs('auctions.*')">Auctions</x-nav-link>
                <x-nav-link href="{{ route('how-it-works') }}" :active="request()->routeIs('how-it-works')">How It Works</x-nav-link>

                @auth
                    {{-- Credits stay in the open deliberately. This is where a
                         customer buys what they bid with -- the way into the
                         auction mechanic, not a settings page. Its history
                         lives in the account menu. --}}
                    <x-nav-link href="{{ route('credits.packages') }}"
                                :active="request()->routeIs('credits.packages')">Credits</x-nav-link>

                    {{-- The unread count is a single indexed COUNT, not a load of
                         the rows, because this runs on every authenticated page. --}}
                    @php($unread = auth()->user()->unreadNotificationCount())
                    <x-nav-link href="{{ route('notifications.index') }}"
                                :active="request()->routeIs('notifications.*')">
                        Notifications
                        @if ($unread > 0)
                            <span class="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-brand-700 px-1.5 py-0.5 text-xs font-bold text-white">
                                {{ $unread > 99 ? '99+' : $unread }}
                            </span>
                        @endif
                    </x-nav-link>

                    @role('admin|super_admin')
                        <x-nav-link href="{{ route('admin.dashboard') }}" :active="request()->routeIs('admin.*')">Admin</x-nav-link>
                    @endrole
                @endauth
            </nav>
        </div>

        <div class="hidden items-center gap-2 md:flex">
            @guest
                <x-button href="{{ route('login') }}" variant="ghost" size="sm">Sign in</x-button>
                <x-button href="{{ route('register') }}" variant="primary" size="sm">Create account</x-button>
            @else
                {{-- The account menu.

                     Closes on Escape and on a click outside, because a menu
                     that can only be dismissed by the button that opened it is
                     a trap for anybody navigating by keyboard. --}}
                <div x-data="{ account: false }"
                     x-on:keydown.escape.window="account = false"
                     x-on:click.outside="account = false"
                     class="relative">

                    <button type="button"
                            x-on:click="account = ! account"
                            x-bind:aria-expanded="account.toString()"
                            aria-haspopup="true"
                            aria-controls="account-menu"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium transition',
                                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                                'bg-brand-50 text-brand-800' => $inAccount,
                                'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $inAccount,
                            ])>
                        {{-- The first name only. A header is not the place for
                             somebody's full legal name. --}}
                        {{ Str::before(auth()->user()->name, ' ') ?: 'Account' }}

                        <svg class="size-4 transition" x-bind:class="account && 'rotate-180'"
                             fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    <div id="account-menu" x-show="account" x-cloak
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95"
                         class="absolute right-0 z-50 mt-2 w-56 origin-top-right rounded-xl border border-slate-200 bg-white py-1.5 shadow-lg"
                         role="menu" aria-label="Your account">

                        @foreach ($accountLinks as [$label, $routeName, $pattern])
                            <a href="{{ route($routeName) }}" role="menuitem"
                               @class([
                                   'block px-4 py-2 text-sm transition',
                                   'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600',
                                   'bg-brand-50 font-semibold text-brand-800' => request()->routeIs($pattern),
                                   'text-slate-700 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs($pattern),
                               ])>{{ $label }}</a>
                        @endforeach

                        <form method="POST" action="{{ route('logout') }}"
                              class="mt-1.5 border-t border-slate-100 px-2 pt-1.5">
                            @csrf
                            <button type="submit" role="menuitem"
                                    class="block w-full rounded-md px-2 py-2 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600">
                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            @endguest
        </div>

        <button type="button"
                class="inline-flex items-center rounded-md p-2 text-slate-600 hover:bg-slate-100 md:hidden"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open.toString()"
                aria-controls="mobile-nav">
            <span class="sr-only">Toggle navigation</span>
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                <path x-show="! open" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                <path x-show="open" x-cloak stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </x-container>

    {{-- The drawer stays flat. It is already a vertical list with room to
         breathe, so nesting a menu inside it would add a tap for nothing --
         the account items are simply grouped under a heading. --}}
    <div id="mobile-nav" x-show="open" x-cloak class="border-t border-slate-200 bg-white md:hidden">
        <x-container class="space-y-1 py-3">
            <x-nav-link href="{{ route('home') }}" class="block" :active="request()->routeIs('home')">Home</x-nav-link>
            <x-nav-link href="{{ route('products.index') }}" class="block" :active="request()->routeIs('products.*')">Shop</x-nav-link>
            <x-nav-link href="{{ route('auctions.index') }}" class="block" :active="request()->routeIs('auctions.*')">Auctions</x-nav-link>
            <x-nav-link href="{{ route('how-it-works') }}" class="block" :active="request()->routeIs('how-it-works')">How It Works</x-nav-link>

            @auth
                <x-nav-link href="{{ route('credits.packages') }}" class="block" :active="request()->routeIs('credits.packages')">Credits</x-nav-link>

                <x-nav-link href="{{ route('notifications.index') }}" class="block" :active="request()->routeIs('notifications.*')">
                    Notifications
                    @php($unreadMobile = auth()->user()->unreadNotificationCount())
                    @if ($unreadMobile > 0)
                        <span class="ml-1 rounded-full bg-brand-700 px-1.5 py-0.5 text-xs font-bold text-white">
                            {{ $unreadMobile > 99 ? '99+' : $unreadMobile }}
                        </span>
                    @endif
                </x-nav-link>

                @role('admin|super_admin')
                    <x-nav-link href="{{ route('admin.dashboard') }}" class="block" :active="request()->routeIs('admin.*')">Admin</x-nav-link>
                @endrole

                <p class="px-3 pb-1 pt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Your account
                </p>

                <x-nav-link href="{{ route('dashboard') }}" class="block" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
                <x-nav-link href="{{ route('wallet') }}" class="block" :active="request()->routeIs('wallet')">Wallet</x-nav-link>
                <x-nav-link href="{{ route('credits.history') }}" class="block" :active="request()->routeIs('credits.history')">Credit history</x-nav-link>
                <x-nav-link href="{{ route('orders.index') }}" class="block" :active="request()->routeIs('orders.*')">Orders</x-nav-link>
                <x-nav-link href="{{ route('addresses.index') }}" class="block" :active="request()->routeIs('addresses.*')">Addresses</x-nav-link>
                <x-nav-link href="{{ route('referrals.index') }}" class="block" :active="request()->routeIs('referrals.*')">Invite friends</x-nav-link>
                <x-nav-link href="{{ route('profile.edit') }}" class="block" :active="request()->routeIs('profile.*')">Profile</x-nav-link>

                <form method="POST" action="{{ route('logout') }}" class="pt-2">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm" class="w-full">Log out</x-button>
                </form>
            @else
                <div class="flex gap-2 pt-2">
                    <x-button href="{{ route('login') }}" variant="secondary" size="sm" class="flex-1">Sign in</x-button>
                    <x-button href="{{ route('register') }}" variant="primary" size="sm" class="flex-1">Create account</x-button>
                </div>
            @endauth
        </x-container>
    </div>
</header>

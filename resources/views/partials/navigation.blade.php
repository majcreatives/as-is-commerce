@php
    // Whether the signed-in customer is somewhere inside their own account.
    // Used to light the menu trigger, so the header still shows where you are
    // when the page you are on lives behind a closed menu.
    $inAccount = request()->routeIs('dashboard', 'wallet', 'orders.*', 'addresses.*', 'referrals.*', 'profile.*', 'credits.history');

    // The Shop checkout this customer still owes on -- a catalogue order
    // (source Buy Now, no auction) that is awaiting payment and is still within
    // its window. Its quantity folds into the cart badge, so placing an order
    // does not make a customer's purchase disappear while it is still owed; the
    // cart page surfaces it as "Payment in progress". Auction-linked checkouts
    // run on their own rails and never show up in the Shop cart. Bound to a
    // single indexed row, with the item quantity summed, because this runs on
    // every page.
    $payableOrder = auth()->user()
        ?->orders()
        ->payableShopOrder(auth()->id())
        ->latest('id')
        ->withSum('items as cart_count', 'quantity')
        ->first();

    // The customer's cart, and how many pieces it holds. The header badge is
    // everything not yet paid for: the basket (intent, nothing reserved) plus
    // any payable Shop checkout's items (already set aside). One indexed row
    // each, summed, on every authenticated page.
    $cartCount = auth()->check()
        ? (int) (\App\Models\Cart::query()
            ->forUser(auth()->user())
            ->withSum('items as cart_count', 'quantity')
            ->value('cart_count'))
        : 0;

    $cartBadge = $cartCount + ($payableOrder ? (int) $payableOrder->cart_count : 0);

    // Unread AND recent, counted once for the whole header. The desktop link,
    // the drawer's link and the hamburger button all read this one figure, so
    // they cannot disagree with each other -- which two separate queries, as
    // this used to be, could if a notification landed between them. A single
    // indexed COUNT, because this runs on every authenticated page. What earns
    // a badge is defined on the user; the complete count lives in the centre.
    $unread = auth()->check() ? auth()->user()->recentUnreadNotificationCount() : 0;

    // What the menu button is called to somebody who cannot see its badge. The
    // count is in the name, so it is announced without opening the menu.
    //
    // Built here, in the block at the top, on purpose. Blade pairs a block-form
    // PHP directive with a pattern that also catches the inline form used
    // further down this file, so a second block down there swallows everything
    // between the two as raw PHP and the page 500s. And a conditional
    // directive written touching the word before it is not compiled at all.
    // (Do not spell the directive names in this comment: the block ends at the
    // first literal closing directive it finds, comment or not.)
    $menuLabel = 'Toggle navigation';

    if ($unread > 0) {
        $menuLabel .= ', '.($unread > 99 ? 'more than 99' : $unread)
            .' unread '.Str::plural('notification', $unread);
    }

    // The account menu, grouped the way somebody looks for things: what is
    // happening, what it cost, where it goes, then who else to tell. Each
    // group is separated by a rule in the panel, which is what stops eight
    // links reading as one undifferentiated list.
    //
    // Icon paths are Heroicons outline, inlined rather than pulled from a
    // package: eight paths do not justify a dependency, and they are constants
    // in this file so nothing user-supplied is ever rendered as raw markup.
    $accountGroups = [
        [
            ['Dashboard', 'dashboard', 'dashboard', [
                'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
            ]],
            ['Orders', 'orders.index', 'orders.*', [
                'M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12A1.125 1.125 0 0 1 19.75 22H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z',
            ]],
        ],
        [
            ['Wallet', 'wallet', 'wallet', [
                'M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9v3',
            ]],
            ['Credit history', 'credits.history', 'credits.history', [
                'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
            ]],
        ],
        [
            ['Addresses', 'addresses.index', 'addresses.*', [
                'M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
                'M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z',
            ]],
            ['Profile', 'profile.edit', 'profile.*', [
                'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
            ]],
        ],
        [
            ['Invite friends', 'referrals.index', 'referrals.*', [
                'M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z',
            ]],
        ],
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

                    <x-nav-link href="{{ route('notifications.index') }}"
                                :active="request()->routeIs('notifications.*')">
                        Notifications
                        @if ($unread > 0)
                            <span class="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-brand-700 px-1.5 py-0.5 text-xs font-bold text-white">
                                {{ $unread > 99 ? '99+' : $unread }}<span class="sr-only"> unread</span>
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
                {{-- The cart: one way into everything a purchase still owes. The
                     badge is the quantity across the basket plus any payable Shop
                     checkout. --}}
                <a href="{{ route('cart.show') }}" wire:navigate
                   class="inline-flex items-center rounded-md p-2 text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                   aria-label="View your cart">
                    <span class="relative inline-flex">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12A1.125 1.125 0 0 1 19.75 22H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z" />
                        </svg>
                        @if ($cartBadge > 0)
                            <span id="cart-count" class="absolute -right-1.5 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[0.65rem] font-bold leading-none text-white ring-2 ring-white"
                                  aria-hidden="true">
                                {{ $cartBadge > 99 ? '99+' : $cartBadge }}
                            </span>
                        @endif
                    </span>
                </a>

                {{-- A pending Shop checkout surfaces inside the cart page, not
                     as a second button: one affordance for everything still
                     owed. --}}

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
                                'inline-flex items-center gap-2 rounded-full py-1 pl-1 pr-2.5 text-sm font-medium transition',
                                'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600',
                                'bg-brand-50 text-brand-800' => $inAccount,
                                'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $inAccount,
                            ])>
                        {{-- Initials rather than an avatar: there is no upload,
                             and a grey silhouette says less than two letters. --}}
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-700 text-xs font-bold text-white"
                              aria-hidden="true">
                            {{ Str::of(auth()->user()->name)->explode(' ')->filter()->take(2)->map(fn ($p) => Str::upper(Str::substr($p, 0, 1)))->implode('') }}
                        </span>

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
                         class="absolute right-0 z-50 mt-2 w-72 origin-top-right rounded-xl border border-slate-200 bg-white shadow-lg ring-1 ring-black/5"
                         role="menu" aria-label="Your account">

                        {{-- Who is signed in, said once and plainly. Phone is
                             the primary identity on this platform and email is
                             optional, so the second line falls back rather than
                             leaving a gap under the name. --}}
                        <div class="border-b border-slate-100 px-4 py-3">
                            <p class="truncate text-sm font-semibold text-slate-900">
                                {{ auth()->user()->name }}
                            </p>
                            <p class="truncate text-sm text-slate-500">
                                {{ auth()->user()->email ?: auth()->user()->phone }}
                            </p>
                        </div>

                        @foreach ($accountGroups as $group)
                            <div @class(['py-1.5', 'border-b border-slate-100' => ! $loop->last])>
                                @foreach ($group as [$label, $routeName, $pattern, $iconPaths])
                                    @php($isActive = request()->routeIs($pattern))

                                    <a href="{{ route($routeName) }}" role="menuitem"
                                       @class([
                                           'flex items-center gap-3 px-4 py-2 text-sm transition',
                                           'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600',
                                           'bg-brand-50 font-semibold text-brand-800' => $isActive,
                                           'text-slate-700 hover:bg-slate-50 hover:text-slate-900' => ! $isActive,
                                       ])>
                                        <svg @class([
                                                'size-5 shrink-0',
                                                'text-brand-700' => $isActive,
                                                'text-slate-400' => ! $isActive,
                                             ])
                                             fill="none" stroke="currentColor" stroke-width="1.5"
                                             viewBox="0 0 24 24" aria-hidden="true">
                                            @foreach ($iconPaths as $d)
                                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" />
                                            @endforeach
                                        </svg>

                                        {{ $label }}
                                    </a>
                                @endforeach
                            </div>
                        @endforeach

                        <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 py-1.5">
                            @csrf
                            <button type="submit" role="menuitem"
                                    class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600">
                                <svg class="size-5 shrink-0 text-slate-400" fill="none" stroke="currentColor"
                                     stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
                                </svg>

                                Log out
                            </button>
                        </form>
                    </div>
                </div>
            @endguest
        </div>

        <button type="button"
                class="relative inline-flex items-center rounded-md p-2 text-slate-600 hover:bg-slate-100 md:hidden"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open.toString()"
                aria-controls="mobile-nav">
            {{-- The accessible name carries the count, so somebody who cannot
                 see the badge is told what it says without opening the menu.
                 The badge itself is hidden from assistive technology, or it
                 would be announced twice. --}}
            <span class="sr-only">{{ $menuLabel }}</span>
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                <path x-show="! open" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                <path x-show="open" x-cloak stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>

            {{-- Something needs attention, visible without opening the menu.
                 The same count the drawer's Notifications link shows inside. --}}
            @if ($unread > 0)
                <span data-menu-notification-badge
                      class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-700 px-1 text-[0.65rem] font-bold leading-none text-white ring-2 ring-white"
                      aria-hidden="true">
                    {{ $unread > 99 ? '99+' : $unread }}
                </span>
            @endif
        </button>
    </x-container>

    {{-- The drawer stays flat. It is already a vertical list with room to
         breathe, so nesting a menu inside it would add a tap for nothing --
         the account items are simply grouped under the same heading. --}}
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
                    @if ($unread > 0)
                        <span class="ml-1 rounded-full bg-brand-700 px-1.5 py-0.5 text-xs font-bold text-white">
                            {{ $unread > 99 ? '99+' : $unread }}<span class="sr-only"> unread</span>
                        </span>
                    @endif
                </x-nav-link>

                <x-nav-link href="{{ route('cart.show') }}" class="block" :active="request()->routeIs('cart.show')">
                    Cart
                    @if ($cartBadge > 0)
                        <span class="ml-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[0.65rem] font-bold leading-none text-white"
                              aria-hidden="true">
                            {{ $cartBadge > 99 ? '99+' : $cartBadge }}
                        </span>
                    @endif
                </x-nav-link>

                @role('admin|super_admin')
                    <x-nav-link href="{{ route('admin.dashboard') }}" class="block" :active="request()->routeIs('admin.*')">Admin</x-nav-link>
                @endrole

                {{-- The same identity block the desktop menu opens with, so a
                     phone shows who is signed in without a trip to Profile. --}}
                <div class="mt-4 border-t border-slate-100 px-3 pb-1 pt-3">
                    <p class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                    <p class="truncate text-sm text-slate-500">
                        {{ auth()->user()->email ?: auth()->user()->phone }}
                    </p>
                </div>

                @foreach ($accountGroups as $group)
                    @foreach ($group as [$label, $routeName, $pattern, $iconPaths])
                        <x-nav-link href="{{ route($routeName) }}" class="flex items-center gap-3"
                                    :active="request()->routeIs($pattern)">
                            <svg class="size-5 shrink-0 text-slate-400" fill="none" stroke="currentColor"
                                 stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                @foreach ($iconPaths as $d)
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" />
                                @endforeach
                            </svg>

                            {{ $label }}
                        </x-nav-link>
                    @endforeach
                @endforeach

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

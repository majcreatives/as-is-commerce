<header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
    <x-container class="flex h-16 items-center justify-between">
        <div class="flex items-center gap-8">
            <a href="{{ route('home') }}" aria-label="{{ config('app.name') }} home">
                <x-logo />
            </a>

            <nav class="hidden items-center gap-1 md:flex" aria-label="Primary">
                <x-nav-link href="{{ route('home') }}" :active="request()->routeIs('home')">Home</x-nav-link>
                <x-nav-link href="{{ route('auctions.index') }}" :active="request()->routeIs('auctions.*')">Auctions</x-nav-link>
                <x-nav-link href="{{ route('how-it-works') }}" :active="request()->routeIs('how-it-works')">How It Works</x-nav-link>

                @auth
                    <x-nav-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
                    <x-nav-link href="{{ route('wallet') }}" :active="request()->routeIs('wallet')">Wallet</x-nav-link>
                    <x-nav-link href="{{ route('credits.packages') }}" :active="request()->routeIs('credits.*')">Credits</x-nav-link>

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
                <x-nav-link href="{{ route('profile.edit') }}" :active="request()->routeIs('profile.*')">Profile</x-nav-link>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm">Log out</x-button>
                </form>
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

    <div id="mobile-nav" x-show="open" x-cloak class="border-t border-slate-200 bg-white md:hidden">
        <x-container class="space-y-1 py-3">
            <x-nav-link href="{{ route('home') }}" class="block" :active="request()->routeIs('home')">Home</x-nav-link>
            <x-nav-link href="{{ route('auctions.index') }}" class="block" :active="request()->routeIs('auctions.*')">Auctions</x-nav-link>
            <x-nav-link href="{{ route('how-it-works') }}" class="block" :active="request()->routeIs('how-it-works')">How It Works</x-nav-link>

            @auth
                <x-nav-link href="{{ route('dashboard') }}" class="block" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
                <x-nav-link href="{{ route('wallet') }}" class="block" :active="request()->routeIs('wallet')">Wallet</x-nav-link>
                <x-nav-link href="{{ route('credits.packages') }}" class="block" :active="request()->routeIs('credits.*')">Credits</x-nav-link>
                <x-nav-link href="{{ route('profile.edit') }}" class="block" :active="request()->routeIs('profile.*')">Profile</x-nav-link>

                @role('admin|super_admin')
                    <x-nav-link href="{{ route('admin.dashboard') }}" class="block" :active="request()->routeIs('admin.*')">Admin</x-nav-link>
                @endrole

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

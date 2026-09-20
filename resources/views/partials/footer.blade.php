{{-- The footer carries the pages that are worth reaching from anywhere but do
     not earn a slot in the main navigation. It does not replace that
     navigation: Shop, Auctions and How It Works stay in the header too.

     Only pages that exist are linked. Blog, Privacy, Cookie and Terms are added
     here when they do -- a footer link to a page that is not there is a broken
     promise printed on every page. --}}

<footer class="border-t border-slate-200 bg-white">
    <x-container class="grid gap-8 py-10 sm:grid-cols-2 lg:grid-cols-4">
        <div class="sm:col-span-2">
            <x-logo />
            <p class="mt-3 max-w-sm text-sm text-slate-500">
                An e-commerce store for Ghana, with a gamified credit auction channel. Prices in GH&#8373;.
            </p>
        </div>

        <nav aria-label="Footer: shopping">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Shop</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <li><a href="{{ route('products.index') }}" class="text-slate-600 hover:text-slate-900">Shop</a></li>
                <li><a href="{{ route('auctions.index') }}" class="text-slate-600 hover:text-slate-900">Auctions</a></li>
                <li><a href="{{ route('how-it-works') }}" class="text-slate-600 hover:text-slate-900">How It Works</a></li>
            </ul>
        </nav>

        <nav aria-label="Footer: company">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Company</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <li><a href="{{ route('about') }}" class="text-slate-600 hover:text-slate-900">About us</a></li>
                <li><a href="{{ route('contact') }}" class="text-slate-600 hover:text-slate-900">Contact us</a></li>
                <li><a href="{{ route('faqs') }}" class="text-slate-600 hover:text-slate-900">FAQs</a></li>
            </ul>
        </nav>
    </x-container>

    <div class="border-t border-slate-100 py-4">
        <x-container class="text-xs text-slate-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </x-container>
    </div>
</footer>

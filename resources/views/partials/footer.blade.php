{{-- The footer carries the pages that are worth reaching from anywhere but do
     not earn a slot in the main navigation. It does not replace that
     navigation: Shop, Auctions and How It Works stay in the header too.

     Only pages that exist are linked. Blog is still to come; Privacy, Cookie and
     Terms are here now, and the legal note below is the one place that says so
     plainly rather than leaving a reader to assume they are final. Partners and
     Success stories are here too, and unlike the rest of this column they are
     content an administrator maintains -- an empty one says so rather than
     showing examples. --}}

<footer class="border-t border-slate-200 bg-white">
    <x-container class="grid gap-8 py-10 sm:grid-cols-2 lg:grid-cols-5">
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
                <li><a href="{{ route('partners.index') }}" class="text-slate-600 hover:text-slate-900">Partners</a></li>
                <li><a href="{{ route('success-stories.index') }}" class="text-slate-600 hover:text-slate-900">Success stories</a></li>
            </ul>
        </nav>

        {{-- The legal column. A draft label rather than a bare link: these pages
             are written from the implementation and are accurate, but they are
             not yet reviewed by a lawyer and name no registered entity. A link
             that looks final when it is not is worse than an honest one. --}}
        <nav aria-label="Footer: legal">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">Legal</h2>
            <ul class="mt-3 space-y-2 text-sm">
                <li><a href="{{ route('privacy') }}" class="text-slate-600 hover:text-slate-900">Privacy notice</a></li>
                <li><a href="{{ route('terms') }}" class="text-slate-600 hover:text-slate-900">Terms of use</a></li>
                <li><a href="{{ route('cookies') }}" class="text-slate-600 hover:text-slate-900">Cookies</a></li>
            </ul>
        </nav>
    </x-container>

    {{-- The newsletter form gets its own full-width row rather than another
         column. It is the one footer element that is not a list of links, it
         needs more width than a column would give it without cramping the label,
         and putting it in the grid would make it the sixth thing competing with
         five columns of navigation.

         Below the grid and above the copyright, because the copyright bar is the
         smallest text on the page and this is the only part of the footer a
         visitor is asked to do something in.

         Inline in the footer only: no popup, no modal, no dismissal state. A
         signup form that arrives unasked over the catalogue is a decision to
         interrupt somebody rather than offer them something, and the footer is
         the one place on the page where asking is appropriate. --}}
    <div class="border-t border-slate-100">
        <x-container class="py-8">
            <div class="max-w-md">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Hear about new auctions
                </h2>

                <livewire:newsletter.signup />
            </div>
        </x-container>
    </div>

    <div class="border-t border-slate-100 py-4">
        <x-container class="text-xs text-slate-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </x-container>
    </div>
</footer>

<x-layouts.app title="About us"
               description="An online store for Ghana, priced in cedis, with an auction channel on some products. Who we are and how we sell.">

    {{-- WHAT THIS PAGE MAY SAY. Only what the platform demonstrably is and
         does. No founding story, no team, no dates, no customer numbers and no
         claim about savings: none of those are recorded anywhere, and an About
         page is the one place a made-up one would read as the most authoritative
         thing on the site.

         SUCCESS STORIES BELONG HERE, and are deliberately absent for now. This
         is the intended home for real, consented customer stories, but there is
         no source for them yet, and a section of invented ones -- or a
         placeholder that looks like one -- would be exactly the fabrication the
         platform refuses everywhere else. The section is added when the first
         real story exists. --}}

    <x-page-header
        :title="'About '.config('app.name')"
        description="An online store for Ghana, with an auction channel on some products." />

    <div class="space-y-6">
        <x-card title="A store first">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    {{ config('app.name') }} is an online store built for Ghana. Every product has
                    a price in cedis, and most can be bought outright the moment you pay.
                </p>
                <p>
                    We hold the stock ourselves. What is listed is what we have, and we do not
                    sell on behalf of other sellers.
                </p>
            </div>
        </x-card>

        <x-card title="Some products are auctioned too">
            <div class="space-y-3 text-sm text-slate-600">
                <p>
                    Alongside the shop, some products are also offered as auctions. You bid with
                    credits, and the largest total of credits committed wins when an auction closes.
                </p>
                <p>
                    Credits are not money, and credits you bid are consumed whether or not you
                    win. We would rather say that plainly here than leave you to find it later.
                </p>
                <p>
                    <a href="{{ route('how-it-works') }}" wire:navigate
                       class="font-semibold text-brand-800 underline">How it all works</a>
                </p>
            </div>
        </x-card>

        <x-card title="How we sell">
            <ul class="list-disc space-y-2 pl-5 text-sm text-slate-600">
                <li>
                    <strong>Payments are verified.</strong> We confirm every payment directly with
                    our payment provider before an order moves on.
                </li>
                <li>
                    <strong>The server decides.</strong> Auction timing, valid bids and winners are
                    settled on our servers, so everyone sees the same outcome.
                </li>
                <li>
                    <strong>We deliver ourselves.</strong> Our own team packs and delivers your
                    order and records each step, so you can see where it has got to.
                </li>
            </ul>
        </x-card>

        <x-card title="Get in touch">
            <p class="text-sm text-slate-600">
                Questions about an order or how something works? See our
                <a href="{{ route('faqs') }}" wire:navigate class="font-semibold text-brand-800 underline">FAQs</a>
                or the
                <a href="{{ route('contact') }}" wire:navigate class="font-semibold text-brand-800 underline">contact page</a>.
            </p>
        </x-card>
    </div>
</x-layouts.app>

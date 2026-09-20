@php
    // Every answer here describes what the platform does, and points to How
    // It Works for the full explanation rather than restating it. Nothing here
    // promises a refund, a delivery time, a return or a saving -- there is no
    // returns process to describe yet, so this page does not invent one, and a
    // question about it is left out rather than answered with a guess.
    //
    // Plain <details>, so it works with no JavaScript and is keyboard- and
    // screen-reader-native.
    $faqs = [
        [
            'Is this a store or an auction site?',
            'A store first. Every product has a Buy Now price in cedis and most can be bought outright the moment you pay. Some products are also auctioned, where you bid with credits and the highest valid bid wins when the auction closes.',
        ],
        [
            'What are credits?',
            'Credits are what you bid with. You buy them in packages priced in cedis. Credits are not money: they cannot be withdrawn, cashed out or converted back, and a credit balance is not a cedis balance.',
        ],
        [
            'Do I get my credits back if I lose?',
            'No. Credits are consumed the moment a bid is accepted, and they stay consumed whether you win, lose or are outbid. Separately, when an auction ends and somebody else ends up with the product, you are given Store Wallet value for the credits you bought. That is not a refund of credits. See "If you bid and do not get the product" on How It Works.',
        ],
        [
            'What decides who wins an auction?',
            'The highest valid bid when the auction closes. Not the last bid, not the most bids, and not whoever led longest. If two bids are for the same amount, the one placed first leads.',
        ],
        [
            'Does the countdown decide when an auction ends?',
            'No. An auction ends on its own recorded end time, decided on our servers, whether or not anybody has the page open. The countdown you see is there to inform you.',
        ],
        [
            'What happens if I win?',
            'You are given a checkout straight away, with a deadline. You pay the auction\'s settlement amount, which is a separate figure in cedis and not your bid converted into money. If the deadline passes the win is forfeited, and your credits stay consumed.',
        ],
        [
            'What is Buy Now, and how do my credits affect it?',
            'Buy Now is a product\'s price in cedis. On a product that is being auctioned, buying it outright ends the auction. Credits you consumed bidding on that auction take money off its Buy Now price, by the value those credits were bought at. That is a discount, not a refund.',
        ],
        [
            'What is Store Wallet, and where can I use it?',
            'A separate balance in cedis. On an ordinary shop purchase it reduces what you pay and the rest is paid as usual; it never covers a whole order. It cannot be used to settle an auction you won or to buy an auctioned product outright, and it cannot be turned back into credits.',
        ],
        [
            'How do payments work?',
            'We confirm every payment directly with our payment provider before an order moves on. A page telling us you paid is not treated as proof of payment.',
        ],
        [
            'How is my order delivered?',
            'By our own team, not a courier. Once a payment is verified we prepare your order and deliver it, and record each step so you can follow it from your order page.',
        ],
    ];
@endphp

<x-layouts.app title="FAQs"
               description="Short answers about credits, bidding, Buy Now, Store Wallet, payments and delivery.">

    <x-page-header
        title="Frequently asked questions"
        description="Short answers. The full explanation is on How It Works." />

    <div class="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white shadow-sm">
        @foreach ($faqs as [$question, $answer])
            <details class="group px-5 py-4">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand-600">
                    {{ $question }}
                    <svg class="size-5 shrink-0 text-slate-400 transition group-open:rotate-180" fill="none" stroke="currentColor"
                         stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </summary>

                <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $answer }}</p>
            </details>
        @endforeach
    </div>

    <p class="mt-6 text-sm text-slate-600">
        Not answered here?
        <a href="{{ route('how-it-works') }}" wire:navigate class="font-semibold text-brand-800 underline">How it works</a>
        goes into more detail, or see how to
        <a href="{{ route('contact') }}" wire:navigate class="font-semibold text-brand-800 underline">contact us</a>.
    </p>
</x-layouts.app>

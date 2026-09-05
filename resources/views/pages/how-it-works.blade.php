<x-layouts.app title="How it works"
               description="Buy products outright in cedis, or bid with credits and compete for them. Here is exactly how bidding, Buy Now and settlement work.">

    {{-- How the platform actually works, in the order a customer meets it.

         Everything here is a description of what the code does. No claim about
         savings, no suggestion that bidding is cheap or likely to win, and no
         wording that would let credits be mistaken for money. --}}

    <x-page-header
        title="How it works"
        description="Two ways to get something: buy it outright, or compete for it with credits." />

    {{-- The one thing somebody must understand before they bid. First, not
         last, and not in small print. --}}
    <x-alert variant="warning" class="mb-8">
        <p class="font-semibold">Credits you bid are gone, win or lose.</p>
        <p class="mt-1">
            When a bid is accepted the credits are consumed immediately. They are not returned if
            somebody outbids you, and they are not returned if you win. Please bid only what you
            are willing to spend.
        </p>
    </x-alert>

    <ol class="space-y-6">
        <li>
            <x-card>
                <div class="flex items-start gap-4">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-700 text-sm font-bold text-white" aria-hidden="true">1</span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Buy credits</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            Credits are what you bid with. You buy them in packages, priced in cedis.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            <strong>Credits are not money.</strong> They cannot be withdrawn, cashed
                            out or converted back, and a credit balance is not a cedis balance. A
                            package price tells you what a bundle of credits costs; it does not make
                            one credit worth any particular amount.
                        </p>
                        <x-button href="{{ route('credits.packages') }}" wire:navigate
                                  variant="secondary" size="sm" class="mt-4">
                            See credit packages
                        </x-button>
                    </div>
                </div>
            </x-card>
        </li>

        <li>
            <x-card>
                <div class="flex items-start gap-4">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-700 text-sm font-bold text-white" aria-hidden="true">2</span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Find an auction</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            Auctions run on real products from our own stock. Each one shows the
                            product, its condition, the standing highest bid in credits, and how
                            long is left.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            The countdown you see is worked out by our servers and shown to you.
                            It does not decide anything: an auction closes on its own recorded end
                            time, whether or not anybody has the page open.
                        </p>
                        <x-button href="{{ route('auctions.index') }}" wire:navigate
                                  variant="secondary" size="sm" class="mt-4">
                            Browse auctions
                        </x-button>
                    </div>
                </div>
            </x-card>
        </li>

        <li>
            <x-card>
                <div class="flex items-start gap-4">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-700 text-sm font-bold text-white" aria-hidden="true">3</span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Place a bid</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            You choose how many credits to commit — there is no fixed cost per bid.
                            A bid of 150 credits consumes exactly 150 credits.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            <strong>The highest valid bid wins</strong> when an auction closes
                            normally. Not the last bid, not the most bids, not whoever led longest.
                            If you are overtaken you can bid again, and if your later bid is the
                            highest when it closes, you win on that bid.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            Every credit you commit is consumed the moment the bid is accepted, and
                            stays consumed however the auction ends.
                        </p>
                    </div>
                </div>
            </x-card>
        </li>

        <li>
            <x-card>
                <div class="flex items-start gap-4">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-700 text-sm font-bold text-white" aria-hidden="true">4</span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Or buy it outright</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            Many auctions also let you buy the product at its Buy Now price in cedis.
                            That price belongs to the product and has nothing to do with the bidding
                            — it does not rise as bids come in.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            <strong>Completing a Buy Now ends the auction immediately</strong> and
                            the product is yours. It is the completed, verified payment that does
                            this — not opening a checkout, and not clicking a button.
                        </p>

                        <div class="mt-4 rounded-lg bg-slate-50 p-4 ring-1 ring-inset ring-slate-200">
                            <p class="text-sm font-semibold text-slate-900">
                                Credits you bid on that auction take money off its Buy Now price.
                            </p>
                            <p class="mt-2 text-sm text-slate-600">
                                Each credit you consumed bidding on <em>that</em> auction reduces its
                                Buy Now price by GH&#8373;1. So 150 credits spent bidding takes
                                GH&#8373;150 off:
                            </p>
                            <dl class="mt-3 space-y-1 text-sm">
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-600">Buy Now price</dt>
                                    <dd class="tabular-nums text-slate-900">GH&#8373; 5,500.00</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-600">Your discount from 150 consumed credits</dt>
                                    <dd class="tabular-nums text-slate-900">&minus; GH&#8373; 150.00</dd>
                                </div>
                                <div class="flex justify-between gap-3 border-t border-slate-200 pt-1 font-semibold">
                                    <dt class="text-slate-900">You pay</dt>
                                    <dd class="tabular-nums text-slate-900">GH&#8373; 5,350.00</dd>
                                </div>
                            </dl>
                            <p class="mt-3 text-sm text-slate-600">
                                <strong>This is a discount, not a refund.</strong> The credits stay
                                consumed; they simply reduce a separate price. Only credits you
                                actually bid on that auction count — unused credits sitting in your
                                wallet do not, and neither do credits you spent on a different
                                auction.
                            </p>
                        </div>
                    </div>
                </div>
            </x-card>
        </li>

        <li>
            <x-card>
                <div class="flex items-start gap-4">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-700 text-sm font-bold text-white" aria-hidden="true">5</span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Winning, and settling</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            If you win an auction, you pay its <strong>settlement amount</strong> —
                            a separate figure in cedis, set for that auction when it was created.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            It is not the Buy Now price, and it is not your bid converted into
                            money. A winning bid of 180 credits and a settlement of GH&#8373;100 are
                            two different quantities: one is what you committed, the other is what
                            you now pay.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            You get a checkout the moment you win, with a deadline. Settle it and
                            the product is yours; miss it and the win is forfeited, with the credits
                            still consumed.
                        </p>
                    </div>
                </div>
            </x-card>
        </li>

        <li>
            <x-card>
                <div class="flex items-start gap-4">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-700 text-sm font-bold text-white" aria-hidden="true">6</span>
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Getting it to you</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            Once a payment is verified we prepare your order and deliver it
                            ourselves. You can follow each step — preparing, dispatched, out for
                            delivery, delivered — from your order page.
                        </p>
                        <p class="mt-2 text-sm text-slate-600">
                            Delivery is handled by our own team rather than through a courier's
                            tracking system, so the reference on your order is ours and the updates
                            come from us.
                        </p>
                    </div>
                </div>
            </x-card>
        </li>
    </ol>

    {{-- The three quantities, once more, in one place. This is the single most
         important distinction on the platform. --}}
    <x-card title="Three different things, never the same thing" class="mt-8">
        <dl class="space-y-4 text-sm">
            <div>
                <dt class="font-semibold text-slate-900">A credit package price</dt>
                <dd class="mt-1 text-slate-600">
                    What a bundle of credits costs in cedis. It does not set a value per credit.
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-slate-900">A bid</dt>
                <dd class="mt-1 text-slate-600">
                    A number of credits you commit to an auction. Never money, and never a price.
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-slate-900">A price in cedis</dt>
                <dd class="mt-1 text-slate-600">
                    A Buy Now price, or an auction's settlement amount. Real money, and never
                    calculated from anybody's bid.
                </dd>
            </div>
        </dl>
    </x-card>

    <div class="mt-8 flex flex-wrap gap-3">
        <x-button href="{{ route('auctions.index') }}" wire:navigate>Explore auctions</x-button>
        <x-button href="{{ route('products.index') }}" wire:navigate variant="secondary">Shop products</x-button>
    </div>
</x-layouts.app>

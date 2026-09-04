<x-layouts.app title="How It Works">
    <x-page-header
        title="How it works"
        description="The mechanics of a credit auction, before you spend anything." />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-card title="1. Buy bidding credits">
            <p class="text-sm text-slate-600">
                Credits are purchased in packages priced in GH&#8373;. Every credit you buy is
                recorded against your account permanently.
            </p>
        </x-card>

        <x-card title="2. Bid an amount of credits">
            <p class="text-sm text-slate-600">
                You choose how many credits to commit &mdash; there is no fixed cost per bid.
                Those credits are consumed when the bid is accepted.
            </p>
        </x-card>

        <x-card title="3. The highest bid wins">
            <p class="text-sm text-slate-600">
                When the auction closes, whoever has placed the highest valid credit bid wins.
                Not the last person to bid, and not whoever bid most often. If you are outbid
                you can bid higher again.
            </p>
        </x-card>

        <x-card title="4. Or buy it outright">
            <p class="text-sm text-slate-600">
                Where Buy Now is offered, any customer can purchase the product at its GH&#8373;
                price at any time. That ends the auction immediately, and the highest bidder
                does not win.
            </p>
        </x-card>
    </div>

    <x-alert variant="info" class="mt-6">
        <strong class="font-semibold">Credits you bid are not money, and they are not refunded.</strong>
        A bid of 150 credits is a bid of 150 credits &mdash; it is not GH&#8373;150, and it does not
        set the product&rsquo;s price. Credits you have already spent bidding on an item will count
        towards a discount if you later buy that item outright, but they are consumed either way,
        whether you win, lose, or buy.
    </x-alert>

    <x-alert variant="warning" class="mt-4">
        <strong class="font-semibold">Auctions are not open yet.</strong>
        Bidding is still being built. Full terms, eligibility requirements and the exact bidding
        rules will be published before the first auction opens.
    </x-alert>
</x-layouts.app>

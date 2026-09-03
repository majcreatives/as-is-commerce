<x-layouts.app title="How It Works">
    <x-page-header
        title="How it works"
        description="The mechanics of a credit-based auction, before you spend anything." />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-card title="1. Buy bidding credits">
            <p class="text-sm text-slate-600">
                Credits are purchased in packages priced in GH&#8373;. Each credit you buy is
                recorded against your account permanently.
            </p>
        </x-card>

        <x-card title="2. Place a bid">
            <p class="text-sm text-slate-600">
                Placing a bid spends a fixed number of credits and puts you in the leading
                position. The cost per bid is shown on every auction before you commit.
            </p>
        </x-card>

        <x-card title="3. Hold the lead">
            <p class="text-sm text-slate-600">
                When someone bids after you, they take the lead. A bid placed near the end
                can extend the countdown, giving others a chance to respond.
            </p>
        </x-card>

        <x-card title="4. Win and check out">
            <p class="text-sm text-slate-600">
                Whoever holds the leading position when the countdown expires wins, and pays
                the auction&rsquo;s checkout price to complete the purchase.
            </p>
        </x-card>
    </div>

    <x-alert variant="warning" class="mt-6">
        <strong class="font-semibold">Credits are spent when you bid.</strong>
        Credits used to place bids are consumed whether or not you go on to win the auction.
        Full terms, refund rules and eligibility requirements will be published before the
        marketplace opens.
    </x-alert>
</x-layouts.app>

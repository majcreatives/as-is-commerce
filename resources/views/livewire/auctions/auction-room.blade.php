{{-- One auction, as a bidder sees it.

     Every figure here was computed on the server and is refreshed by polling.
     Nothing on this page decides anything: the auction ends when its stored
     end time says so, and the winner is resolved from the bid records.

     The three numbers below are deliberately never mixed. The Buy Now price
     and the settlement amount are money, in GHS. The highest bid is a count of
     credits and is never written with a currency symbol. --}}

<div wire:poll.5s>
    <div class="mb-6 flex flex-wrap items-center gap-2">
        <x-badge :classes="$auction->status->badgeClasses()">{{ $auction->status->label() }}</x-badge>

        @if ($auction->closure_reason)
            <x-badge :classes="$auction->closure_reason->badgeClasses()">
                {{ $auction->closure_reason->label() }}
            </x-badge>
        @endif
    </div>

    <x-page-header
        :title="$auction->product->name"
        :description="$auction->product->short_description" />

    @if (session('bid-placed'))
        <x-alert variant="success" class="mb-6">{{ session('bid-placed') }}</x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- ---------------------------------------------------- The auction --}}
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Highest Bid (Credits)"
                    subtitle="The highest valid credit bid wins when this auction closes.">
                @if ($highestBid)
                    <p class="text-4xl font-bold tracking-tight text-slate-900">
                        {{ number_format($highestBid->amount_credits) }}
                        <span class="text-base font-semibold text-slate-500">credits</span>
                    </p>

                    <p class="mt-1 text-sm text-slate-500">
                        {{ $auction->bid_count }} {{ Str::plural('bid', $auction->bid_count) }} placed.
                    </p>
                @else
                    {{-- No bids is an honest state, not a zero. --}}
                    <p class="text-lg font-semibold text-slate-700">No bids yet</p>
                    <p class="mt-1 text-sm text-slate-500">Be the first to commit credits to this auction.</p>
                @endif

                <x-alert variant="warning" class="mt-4">
                    Credits are not cash and are never converted to GH₵. A bid of 150 credits is
                    <strong>not</strong> GH₵150, and it is not the price of this product.
                </x-alert>
            </x-card>

            @if ($auction->status->isOpen())
                <x-card title="Time remaining"
                        subtitle="Counted by our servers. Closing your browser does not affect this auction.">
                    <p class="text-2xl font-semibold tabular-nums text-slate-900">
                        @if ($secondsRemaining === null)
                            Not started
                        @else
                            {{ gmdate($secondsRemaining >= 3600 ? 'G\h i\m s\s' : 'i\m s\s', $secondsRemaining) }}
                        @endif
                    </p>

                    <p class="mt-2 text-sm text-slate-500">
                        Scheduled to end {{ $auction->ends_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}.

                        @if ($auction->rules()->extensionsEnabled())
                            A bid placed in the final
                            {{ $auction->rules()->closingWindowSeconds }} seconds extends the clock, at
                            the latest until
                            {{ $latestPossibleEnd?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}.
                            Extending gives everyone more time to bid higher — it does not change who wins.
                        @endif
                    </p>
                </x-card>
            @endif

            {{-- ------------------------------------------------- Placing a bid --}}
            @if ($auction->status->acceptsBids())
                <x-card title="Place a bid"
                        subtitle="You choose how many credits to commit. They are consumed immediately and permanently.">
                    @auth
                        @can('bids.place')
                            <form wire:submit="bid" class="space-y-4">
                                <x-field label="Credits to commit" name="amount"
                                         :error="$errors->first('amount')"
                                         :hint="$this->smallestValidBid(app(App\Domain\Auction\Services\BidValidator::class))
                                            ? 'The smallest valid bid right now is '.number_format($this->smallestValidBid(app(App\Domain\Auction\Services\BidValidator::class))).' credits.'
                                            : 'This auction sets no minimum. Any whole number of credits is a valid bid.'">
                                    <x-input wire:model="amount" inputmode="numeric"
                                             placeholder="e.g. 150"
                                             :error="$errors->has('amount')" />
                                </x-field>

                                <div class="flex flex-wrap items-center gap-3">
                                    <x-button type="submit" wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="bid">Commit credits</span>
                                        <span wire:loading wire:target="bid">Placing…</span>
                                    </x-button>

                                    <p class="text-sm text-slate-500">
                                        Your balance:
                                        <strong>{{ number_format(auth()->user()->creditWallet?->balance ?? 0) }}</strong>
                                        credits
                                    </p>
                                </div>
                            </form>

                            <x-alert variant="warning" class="mt-4">
                                <strong>Credits used for bids are permanently consumed.</strong>
                                They are not returned if you are outbid, and they are not returned if you win.
                                What they do earn is GH₵1 off this product's Buy Now price for each credit —
                                see below.
                            </x-alert>
                        @else
                            <x-alert variant="info">Your account is not able to place bids.</x-alert>
                        @endcan
                    @else
                        <x-alert variant="info">
                            <a href="{{ route('login') }}" wire:navigate class="font-semibold underline">Sign in</a>
                            to bid on this auction.
                        </x-alert>
                    @endauth
                </x-card>
            @endif

            {{-- ------------------------------------------------------- Outcome --}}
            @if ($auction->endedByBuyNow())
                <x-card title="This auction ended early">
                    <p class="text-sm text-slate-700">
                        Someone bought this product outright, which ends the auction immediately.
                        The highest bidder did not win, and credits already committed stay consumed.
                    </p>
                </x-card>
            @elseif ($auction->hasBidWinner())
                <x-card title="Result">
                    <p class="text-sm text-slate-700">
                        Won by the highest valid credit bid of
                        <strong>{{ number_format($auction->winningBid?->amount_credits ?? 0) }} credits</strong>.
                        The winner pays the auction settlement amount of
                        <strong><x-money :amount="$auction->settlementAmount()" /></strong> plus applicable
                        delivery and checkout charges.
                    </p>

                    @if (auth()->id() === $auction->winner_user_id)
                        <x-alert variant="success" class="mt-4">
                            You won this auction. Settlement checkout is not available yet — it arrives
                            in a later stage of the platform.
                        </x-alert>
                    @endif
                </x-card>
            @endif

            {{-- ------------------------------------------------- Bid history --}}
            <x-card title="Bid history" subtitle="Every accepted bid, newest first." :padded="false">
                @if ($history->isEmpty())
                    <div class="p-5">
                        <x-empty-state title="No bids yet"
                                       description="Nothing has been committed to this auction." />
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Bidder</th>
                                    <th class="px-5 py-3 font-semibold">Bid (credits)</th>
                                    <th class="px-5 py-3 font-semibold">Placed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($history as $bid)
                                    <tr class="{{ $highestBid && $bid->id === $highestBid->id ? 'bg-emerald-50/60' : '' }}">
                                        <td class="px-5 py-3 text-slate-700">
                                            {{-- Other bidders are not named. Who is bidding is not
                                                 public information; how much they bid is. --}}
                                            {{ $bid->user_id === auth()->id() ? 'You' : 'Bidder #'.$bid->sequence }}
                                        </td>
                                        <td class="px-5 py-3 font-semibold tabular-nums text-slate-900">
                                            {{ number_format($bid->amount_credits) }}
                                        </td>
                                        <td class="px-5 py-3 text-slate-500">
                                            {{ $bid->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        {{-- --------------------------------------------------------- Sidebar --}}
        <div class="space-y-6">
            @php($quote = $this->buyNowQuote(app(App\Domain\Auction\Services\BuyNowPricer::class)))

            <x-card title="Buy it outright" subtitle="Buying now ends this auction immediately.">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">Buy Now price</dt>
                        <dd class="font-semibold tabular-nums text-slate-900">
                            <x-money :amount="$quote->listPrice" />
                        </dd>
                    </div>

                    @if ($quote->hasDiscount())
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-slate-600">
                                Your credit discount
                                <span class="block text-xs text-slate-500">
                                    {{ number_format($quote->eligibleCredits) }} credits you have already
                                    spent bidding on this auction, at GH₵1 each
                                </span>
                            </dt>
                            <dd class="font-semibold tabular-nums text-emerald-700">
                                −<x-money :amount="$quote->discount" />
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-4 border-t border-slate-100 pt-3">
                            <dt class="font-semibold text-slate-900">You would pay</dt>
                            <dd class="text-lg font-bold tabular-nums text-slate-900">
                                <x-money :amount="$quote->payable" />
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($auction->rules()->buyNowCreditDiscountEnabled && ! $quote->hasDiscount())
                    <p class="mt-4 text-sm text-slate-500">
                        Each credit you spend bidding on this auction takes GH₵1 off this price.
                    </p>
                @endif

                @if ($quote->available)
                    {{-- Deliberately not a purchase button. Buying outright needs a
                         payment, and payments for products arrive in a later stage.
                         A button that ended the auction without one would be
                         recording a sale that never happened. --}}
                    <x-alert variant="info" class="mt-4">
                        Buy Now checkout is not available yet. When it opens, a completed purchase
                        will end this auction immediately and the highest bidder will not win.
                    </x-alert>
                @elseif ($quote->unavailableReason)
                    <x-alert variant="info" class="mt-4">{{ $quote->unavailableReason }}</x-alert>
                @endif
            </x-card>

            <x-card title="If the auction closes normally">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-slate-600">Auction Settlement Amount</dt>
                        <dd class="font-semibold tabular-nums text-slate-900">
                            <x-money :amount="$auction->settlementAmount()" />
                        </dd>
                    </div>
                </dl>

                <p class="mt-3 text-sm text-slate-600">
                    The highest valid credit bidder wins and pays this amount plus applicable delivery
                    and checkout charges. It is a separate figure from the Buy Now price, and it is
                    <strong>not</strong> worked out from anybody's bid.
                </p>
            </x-card>

            @auth
                <x-card title="Your bids on this auction">
                    @php($mine = $this->myBids())

                    @if ($mine->isEmpty())
                        <p class="text-sm text-slate-500">You have not bid on this auction.</p>
                    @else
                        <ul class="space-y-2 text-sm">
                            @foreach ($mine as $bid)
                                <li class="flex items-baseline justify-between gap-4">
                                    <span class="text-slate-600">
                                        {{ $bid->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M, H:i') }}
                                    </span>
                                    <span class="font-semibold tabular-nums text-slate-900">
                                        {{ number_format($bid->amount_credits) }} credits
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        <p class="mt-4 border-t border-slate-100 pt-3 text-sm text-slate-600">
                            <strong>{{ number_format($mine->sum('amount_credits')) }} credits</strong>
                            consumed on this auction. They are gone whatever happens, and they are what
                            earns your Buy Now discount above.
                        </p>
                    @endif
                </x-card>
            @endauth

            <x-card title="The product">
                <p class="text-sm text-slate-600">{{ $auction->product->short_description }}</p>

                <x-button variant="secondary" size="sm" class="mt-4"
                          href="{{ route('products.show', $auction->product->slug) }}" wire:navigate>
                    View product details
                </x-button>
            </x-card>
        </div>
    </div>
</div>

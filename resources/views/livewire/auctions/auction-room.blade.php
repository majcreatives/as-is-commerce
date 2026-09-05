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

    @error('checkout')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

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
                            @php
                                $smallest = $this->smallestValidBid(app(App\Domain\Auction\Services\BidValidator::class));
                                $balance = app(App\Domain\Credit\Services\CreditLedgerService::class)
                                    ->walletFor(auth()->user())->spendableBalance();
                            @endphp

                            {{-- Where the bidder stands, before anything else.
                                 No other bidder is named: only the amount to
                                 beat, which is all anybody needs. --}}
                            @if ($this->viewerIsLeading())
                                <x-alert variant="success" class="mb-4" role="status">
                                    You hold the highest bid right now.
                                </x-alert>
                            @elseif ($this->viewerIsOutbid())
                                <x-alert variant="warning" class="mb-4" role="status">
                                    <strong>You have been outbid.</strong>
                                    The highest bid is now
                                    <x-credits :amount="$auction->highest_bid_credits ?? 0" />@if ($smallest),
                                        and the smallest valid bid is <x-credits :amount="$smallest" />@endif.
                                    The <x-credits :amount="$this->viewerCommittedCredits()" /> you have
                                    already committed stay consumed either way.
                                </x-alert>
                            @endif

                            @if ($confirming)
                                {{-- The confirmation. Committing credits cannot
                                     be undone, so it takes two deliberate
                                     actions -- and the figures here are the
                                     server's, re-read again under a lock when
                                     the bid is actually placed. --}}
                                <div class="rounded-lg border-2 border-accent-300 bg-accent-50/50 p-4"
                                     role="alertdialog" aria-labelledby="confirm-bid-heading">
                                    <h3 id="confirm-bid-heading" class="text-sm font-bold text-slate-900">
                                        You are about to bid <x-credits :amount="(int) $amount" />.
                                    </h3>

                                    <dl class="mt-3 space-y-1.5 text-sm">
                                        <div class="flex justify-between gap-3">
                                            <dt class="text-slate-600">Current highest bid</dt>
                                            <dd class="tabular-nums text-slate-900">
                                                @if ($highestBid)
                                                    <x-credits :amount="$highestBid->amount_credits" />
                                                @else
                                                    No bids yet
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <dt class="text-slate-600">Your bid</dt>
                                            <dd class="font-semibold tabular-nums text-slate-900">
                                                <x-credits :amount="(int) $amount" />
                                            </dd>
                                        </div>
                                        <div class="flex justify-between gap-3 border-t border-accent-200 pt-1.5">
                                            <dt class="text-slate-600">Your balance afterwards</dt>
                                            <dd class="tabular-nums text-slate-900">
                                                <x-credits :amount="max(0, $balance - (int) $amount)" />
                                            </dd>
                                        </div>
                                    </dl>

                                    <p class="mt-3 text-sm font-semibold text-slate-900">
                                        <x-credits :amount="(int) $amount" /> will be consumed immediately
                                        and will not be returned if you lose.
                                    </p>

                                    @error('amount')
                                        <p class="mt-3 text-sm font-medium text-red-700" role="alert">{{ $message }}</p>
                                    @enderror

                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <x-button wire:click="bid" wire:loading.attr="disabled" wire:target="bid">
                                            <span wire:loading.remove wire:target="bid">Confirm bid</span>
                                            <span wire:loading wire:target="bid">Placing…</span>
                                        </x-button>

                                        <x-button variant="ghost" wire:click="cancelBid"
                                                  wire:loading.attr="disabled" wire:target="bid">
                                            Cancel
                                        </x-button>
                                    </div>
                                </div>
                            @else
                                <form wire:submit="review" class="space-y-4">
                                    <x-field label="Credits to commit" name="amount"
                                             :error="$errors->first('amount')"
                                             :hint="$smallest
                                                ? 'The smallest valid bid right now is '.number_format($smallest).' credits.'
                                                : 'This auction sets no minimum. Any whole number of credits is a valid bid.'">
                                        <x-input wire:model="amount" inputmode="numeric"
                                                 placeholder="e.g. 150"
                                                 :error="$errors->has('amount')" />
                                    </x-field>

                                    <div class="flex flex-wrap items-center gap-3">
                                        <x-button type="submit" wire:loading.attr="disabled">
                                            <span wire:loading.remove wire:target="review">Review bid</span>
                                            <span wire:loading wire:target="review">Checking…</span>
                                        </x-button>

                                        <p class="text-sm text-slate-500">
                                            Your balance:
                                            <strong><x-credits :amount="$balance" /></strong>
                                        </p>
                                    </div>
                                </form>
                            @endif

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
                <x-card title="Sold via Buy Now">
                    <p class="text-sm text-slate-700">
                        Someone bought this product outright, which ends the auction immediately.
                        There is no auction winner: the highest bidder did not win, and credits
                        already committed stay consumed.
                    </p>

                    @if ($this->viewerLost())
                        <x-alert variant="info" class="mt-4">
                            You bid on this auction and it was bought outright before it closed.
                            The
                            <strong>{{ number_format($this->myBids()->sum('amount_credits')) }}
                            credits</strong> you committed remain consumed, as bid credits always
                            are.
                        </x-alert>
                    @endif
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
                            <p class="font-semibold">You won this auction.</p>
                            <p class="mt-1">
                                Your bid credits are already consumed. What is left to pay is the
                                settlement amount of
                                <strong><x-money :amount="$auction->settlementAmount()" /></strong>
                                plus applicable charges — not your bid converted into GH&#8373;.
                            </p>
                        </x-alert>

                        @if ($auction->settlement_due_at)
                            <p class="mt-3 text-sm text-slate-600">
                                Settle by
                                <strong>{{ $auction->settlement_due_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}</strong>.
                                After that the auction is forfeited and the product goes back on
                                sale. Your consumed credits are not returned.
                            </p>
                        @endif

                        @can('checkout.create')
                            @if ($settlementOrder && $settlementOrder->isPayable())
                                {{-- Opened when the auction closed, so the deadline is not
                                     already running against a winner who had not visited. --}}
                                <x-button href="{{ route('checkout.show', $settlementOrder) }}"
                                          wire:navigate class="mt-4">
                                    Go to settlement checkout
                                </x-button>
                            @elseif ($settlementOrder?->isPaid())
                                <x-alert variant="success" class="mt-4">
                                    Settled. Order
                                    <a href="{{ route('orders.show', $settlementOrder) }}"
                                       wire:navigate class="font-semibold underline">{{ $settlementOrder->order_number }}</a>.
                                </x-alert>
                            @else
                                <x-button wire:click="settle" class="mt-4">
                                    Settle this auction
                                </x-button>
                            @endif
                        @endcan
                    @elseif ($this->viewerLost())
                        {{-- A losing bidder is owed a straight answer, and the truth
                             about their credits. There is deliberately no refund
                             control here, because there is no refund. --}}
                        <x-alert variant="info" class="mt-4">
                            <p class="font-semibold">You did not win this auction.</p>
                            <p class="mt-1">
                                The winning bid was
                                {{ number_format($auction->winningBid?->amount_credits ?? 0) }}
                                credits. The
                                <strong>{{ number_format($this->myBids()->sum('amount_credits')) }}
                                credits</strong> you committed remain consumed — bid credits are
                                spent when the bid is accepted and are not returned.
                            </p>
                        </x-alert>
                    @endif
                </x-card>
            @elseif ($auction->hasEnded() && $this->viewerLost())
                <x-card title="This auction ended">
                    <p class="text-sm text-slate-700">
                        {{ $auction->closure_reason?->label() ?? $auction->status->label() }}.
                        Nobody won it on a bid.
                    </p>
                    <p class="mt-2 text-sm text-slate-600">
                        The
                        <strong>{{ number_format($this->myBids()->sum('amount_credits')) }}
                        credits</strong> you committed remain consumed.
                    </p>
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
                    @auth
                        @can('checkout.create')
                            {{-- Opens a checkout at a frozen price. It does NOT end the
                                 auction: only a payment we have verified with Paystack
                                 does that. --}}
                            <x-button wire:click="buyNow" class="mt-4 w-full"
                                      wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="buyNow">
                                    Buy now for <x-money :amount="$quote->payable" />
                                </span>
                                <span wire:loading wire:target="buyNow">Opening checkout…</span>
                            </x-button>

                            <p class="mt-3 text-xs text-slate-500">
                                Starting a checkout does not end this auction. It ends the moment we
                                have confirmed your payment — and then the highest bidder does not
                                win.
                            </p>
                        @endcan
                    @else
                        <x-alert variant="info" class="mt-4">
                            <a href="{{ route('login') }}" wire:navigate class="font-semibold underline">Sign in</a>
                            to buy this outright.
                        </x-alert>
                    @endauth
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
                <x-card title="Your credits">
                    <p class="text-2xl font-bold tabular-nums text-slate-900">
                        {{ number_format(auth()->user()->creditWallet?->balance ?? 0) }}
                        <span class="text-sm font-semibold text-slate-500">credits</span>
                    </p>
                    <p class="mt-2 text-xs text-slate-500">
                        Your spendable balance. Credits are not money and are never converted to
                        GH&#8373;.
                    </p>

                    <x-button variant="secondary" size="sm" class="mt-4"
                              href="{{ route('credits.packages') }}" wire:navigate>
                        Buy more credits
                    </x-button>
                </x-card>

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

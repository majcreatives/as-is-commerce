{{-- One auction, as a bidder sees it.

     Every figure here was computed on the server and is refreshed by polling.
     Nothing on this page decides anything: the auction ends when its stored
     end time says so, and the winner is resolved from the bid records.

     The three numbers below are deliberately never mixed. The Buy Now price
     and the settlement amount are money, in GHS. The highest bid is a count of
     credits and is never written with a currency symbol. --}}

{{-- The interval is the server's, not the browser's, and it decides nothing:
     an auction ends when its stored end time says so. Tight inside the closing
     window, looser when the auction is days away, loosest once it has ended. --}}
<div wire:poll.{{ $pollSeconds }}s
     data-auction-room="{{ $auction->id }}"
     data-auction-component="{{ $this->getId() }}">
    <div class="mb-6 flex flex-wrap items-center gap-2">
        <x-badge :classes="$auction->status->badgeClasses()" data-auction-status>{{ $auction->status->label() }}</x-badge>

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
            <x-card :title="$bidModel->leaderLabel()"
                    :subtitle="$bidModel->ruleSentence()">
                @if ($highestBid)
                    {{-- The two hooks a live update may repaint. Both are a
                         count of credits or a count of bids -- never money --
                         and the next poll re-renders them from the database
                         either way. --}}
                    <p class="text-4xl font-bold tracking-tight text-slate-900">
                        <span data-auction-highest-credits>{{ number_format($highestBid->rankingValue()) }}</span>
                        <span class="text-base font-semibold text-slate-500">credits</span>
                    </p>

                    <p class="mt-1 text-sm text-slate-500" data-auction-bid-count>
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
                @if (! $bidModel->isCumulative())
                    {{-- An auction created under the earlier rules. It is shown, and
                         it is not bid on through this page: there is no free-text
                         amount here any more, and nothing in the product creates an
                         auction of this kind. --}}
                    <x-card title="Bidding">
                        <x-alert variant="info">
                            This auction was created under the earlier bidding rules and is not taking
                            new bids.
                        </x-alert>
                    </x-card>
                @else
                <x-card title="Place a bid"
                        :subtitle="'Each bid puts you exactly '.number_format($stepCredits).' '.Str::plural('credit', $stepCredits).' ahead of the leader. Credits are consumed immediately and permanently.'">
                    @auth
                        @can('bids.place')
                            @php
                                // Read once by the component. Blade asks for
                                // nothing the server has not already worked out
                                // for this render.
                                $balance = $spendableBalance;
                                $leaderTotal = $highestBid?->rankingValue();
                            @endphp

                            {{-- Where the bidder stands, before anything else. No
                                 other bidder is named: only the figure to beat,
                                 which is all anybody needs. --}}
                            @if ($viewerIsLeading)
                                <x-alert variant="success" class="mb-4" role="status">
                                    <strong>You hold the lead</strong> with
                                    <x-credits :amount="$committedCredits" />. You can bid again once
                                    somebody overtakes you.
                                </x-alert>
                            @elseif ($viewerIsOutbid)
                                <x-alert variant="warning" class="mb-4" role="status">
                                    <strong>You have been outbid.</strong>
                                    The {{ $bidModel->leaderNoun() }} is now
                                    <x-credits :amount="$leaderTotal ?? 0" />.
                                    The <x-credits :amount="$committedCredits" /> you have
                                    already committed stay consumed either way.
                                </x-alert>
                            @endif

                            @if ($confirming && $confirmedAmount !== null)
                                {{-- The confirmation. Committing credits cannot be
                                     undone, so it takes two deliberate actions -- and
                                     the figure here is the one the server showed, which
                                     the domain re-derives under a lock when the bid is
                                     actually placed. A figure that has moved is
                                     refused, never substituted. --}}
                                <div class="rounded-lg border-2 border-accent-300 bg-accent-50/50 p-4"
                                     role="alertdialog" aria-labelledby="confirm-bid-heading">
                                    <h3 id="confirm-bid-heading" class="text-sm font-bold text-slate-900">
                                        You are about to bid <x-credits :amount="$confirmedAmount" />.
                                    </h3>

                                    <dl class="mt-3 space-y-1.5 text-sm">
                                        <div class="flex justify-between gap-3">
                                            <dt class="text-slate-600">Current {{ $bidModel->leaderNoun() }}</dt>
                                            <dd class="tabular-nums text-slate-900">
                                                @if ($highestBid)
                                                    <x-credits :amount="$leaderTotal" />
                                                @else
                                                    No bids yet
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <dt class="text-slate-600">Your total now</dt>
                                            <dd class="tabular-nums text-slate-900">
                                                <x-credits :amount="$committedCredits" />
                                            </dd>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <dt class="text-slate-600">Your total after this bid</dt>
                                            <dd class="font-semibold tabular-nums text-slate-900">
                                                <x-credits :amount="$committedCredits + $confirmedAmount" />
                                            </dd>
                                        </div>
                                        <div class="flex justify-between gap-3 border-t border-accent-200 pt-1.5">
                                            <dt class="text-slate-600">Your balance afterwards</dt>
                                            <dd class="tabular-nums text-slate-900">
                                                <x-credits :amount="max(0, $balance - $confirmedAmount)" />
                                            </dd>
                                        </div>
                                    </dl>

                                    <p class="mt-3 text-sm font-semibold text-slate-900">
                                        <x-credits :amount="$confirmedAmount" /> will be consumed immediately
                                        and will not be returned if you lose.
                                    </p>

                                    @error('bid')
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
                                @error('bid')
                                    <x-alert variant="danger" class="mb-4" role="alert">{{ $message }}</x-alert>
                                @enderror

                                @if ($nextBid !== null)
                                    {{-- The one bid this viewer could place. Worked out
                                         by the server; the button carries the figure
                                         shown so a stale page is told so. --}}
                                    <p class="text-sm text-slate-700">
                                        @if ($highestBid)
                                            To take the lead, add
                                            <strong><x-credits :amount="$nextBid" /></strong>
                                            &mdash; you would lead with
                                            <x-credits :amount="$committedCredits + $nextBid" />.
                                        @else
                                            Be the first: the opening bid on this auction is
                                            <strong><x-credits :amount="$nextBid" /></strong>.
                                        @endif
                                    </p>

                                    <div class="mt-4 flex flex-wrap items-center gap-3">
                                        @if ($nextBid > $balance)
                                            <x-button type="button" disabled>
                                                Bid {{ number_format($nextBid) }} {{ Str::plural('credit', $nextBid) }}
                                            </x-button>

                                            <p class="text-sm text-slate-600" role="status">
                                                You need <strong><x-credits :amount="$nextBid - $balance" /></strong>
                                                more to bid.
                                                <a href="{{ route('credits.packages') }}" wire:navigate
                                                   class="font-semibold text-brand-800 underline">Buy credits</a>
                                            </p>
                                        @else
                                            <x-button wire:click="review({{ $nextBid }})" wire:loading.attr="disabled"
                                                      wire:target="review">
                                                <span wire:loading.remove wire:target="review">
                                                    Bid {{ number_format($nextBid) }} {{ Str::plural('credit', $nextBid) }}
                                                </span>
                                                <span wire:loading wire:target="review">Checking…</span>
                                            </x-button>

                                            <p class="text-sm text-slate-500">
                                                Your balance:
                                                <strong><x-credits :amount="$balance" /></strong>
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            @endif

                            <x-alert variant="warning" class="mt-4">
                                <strong>Credits used for bids are permanently consumed.</strong>
                                They are not returned if you are outbid, and they are not returned if you win.
                                What they do earn: each credit takes its actual purchased value off this
                                product's Buy Now price — see below.
                            </x-alert>
                        @else
                            <x-alert variant="info">Your account is not able to place bids.</x-alert>
                        @endcan
                    @else
                        {{-- A visitor sees what joining costs: the same figure a
                             signed-in newcomer would be shown. --}}
                        @if ($nextBid !== null)
                            <p class="mb-3 text-sm text-slate-700">
                                @if ($highestBid)
                                    To take the lead right now, a new bidder adds
                                    <strong><x-credits :amount="$nextBid" /></strong>.
                                @else
                                    The opening bid on this auction is
                                    <strong><x-credits :amount="$nextBid" /></strong>.
                                @endif
                            </p>
                        @endif

                        <x-alert variant="info">
                            <a href="{{ route('login') }}" wire:navigate class="font-semibold underline">Sign in</a>
                            to bid on this auction.
                        </x-alert>
                    @endauth
                </x-card>
                @endif
            @endif

            {{-- ------------------------------------------------------- Outcome --}}
            @if ($auction->endedByBuyNow())
                <x-card title="Sold via Buy Now">
                    <p class="text-sm text-slate-700">
                        Someone bought this product outright, which ends the auction immediately.
                        There is no auction winner: the bidder who was leading did not win, and credits
                        already committed stay consumed.
                    </p>

                    @if ($viewerLost)
                        <x-alert variant="info" class="mt-4">
                            You bid on this auction and it was bought outright before it closed.
                            The
                            <strong>{{ number_format($committedCredits) }}
                            credits</strong> you committed remain consumed, as bid credits always
                            are.
                        </x-alert>
                    @endif
                </x-card>
            @elseif ($auction->hasBidWinner())
                <x-card title="Result">
                    <p class="text-sm text-slate-700">
                        {{ $bidModel->winnerSentence($auction->winningBid?->rankingValue() ?? 0) }}
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
                    @elseif ($viewerLost)
                        {{-- A losing bidder is owed a straight answer, and the truth
                             about their credits. There is deliberately no refund
                             control here, because there is no refund. --}}
                        <x-alert variant="info" class="mt-4">
                            <p class="font-semibold">You did not win this auction.</p>
                            <p class="mt-1">
                                The {{ $bidModel->winningFigure() }} was
                                {{ number_format($auction->winningBid?->rankingValue() ?? 0) }}
                                credits. The
                                <strong>{{ number_format($committedCredits) }}
                                credits</strong> you committed remain consumed — bid credits are
                                spent when the bid is accepted and are not returned.
                            </p>
                        </x-alert>
                    @endif
                </x-card>
            @elseif ($auction->hasEnded() && $viewerLost)
                <x-card title="This auction ended">
                    <p class="text-sm text-slate-700">
                        {{ $auction->closure_reason?->label() ?? $auction->status->label() }}.
                        Nobody won it on a bid.
                    </p>
                    <p class="mt-2 text-sm text-slate-600">
                        The
                        <strong>{{ number_format($committedCredits) }}
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
                                    @if ($bidModel->isCumulative())
                                        <th class="px-5 py-3 font-semibold">Credits added</th>
                                        <th class="px-5 py-3 font-semibold">Total after</th>
                                    @else
                                        <th class="px-5 py-3 font-semibold">Bid (credits)</th>
                                    @endif
                                    <th class="px-5 py-3 font-semibold">Placed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($history as $bid)
                                    <tr class="{{ $highestBid && $bid->id === $highestBid->id ? 'bg-emerald-50/60' : '' }}">
                                        <td class="px-5 py-3 text-slate-700">
                                            {{-- Other bidders are not named. Who is bidding is not
                                                 public information; how much they bid is.

                                                 The number identifies a participant, not a bid: the
                                                 person who bid first is Bidder #1 every time they
                                                 appear, so three bids from one bidder do not read as
                                                 three rivals. Worked out on the server across the
                                                 whole auction. --}}
                                            {{ $bid->user_id === auth()->id()
                                                ? 'You'
                                                : 'Bidder #'.($participants[$bid->user_id] ?? 1) }}
                                        </td>
                                        @if ($bidModel->isCumulative())
                                            <td class="px-5 py-3 tabular-nums text-slate-700">
                                                +{{ number_format($bid->amount_credits) }}
                                            </td>
                                            <td class="px-5 py-3 font-semibold tabular-nums text-slate-900">
                                                {{ number_format($bid->rankingValue()) }}
                                            </td>
                                        @else
                                            <td class="px-5 py-3 font-semibold tabular-nums text-slate-900">
                                                {{ number_format($bid->amount_credits) }}
                                            </td>
                                        @endif
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
            @php($quote = $buyNowQuote)

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
                                    spent bidding on this auction, each valued at the price it was bought at
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
                                    Credits you spend bidding on this auction take their actual purchased
                                    value off this price. Credits that cost nothing earn nothing.
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
                                have confirmed your payment — and then whoever was leading does not
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
                    The winner pays this amount plus applicable delivery
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
                    @php($mine = $myBids)

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
                                        @if ($bidModel->isCumulative())
                                            <span class="font-normal text-slate-500">(total {{ number_format($bid->rankingValue()) }})</span>
                                        @endif
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

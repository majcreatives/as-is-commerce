<?php

declare(strict_types=1);

namespace App\Livewire\Auctions;

use App\Domain\Auction\Actions\PlaceBid;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Auction\Services\BidValidator;
use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Domain\Orders\Actions\StartBuyNowCheckout;
use App\Domain\Orders\Actions\StartSettlementCheckout;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Shared\Idempotency\ConcurrentOperationInProgress;
use App\Models\Auction;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One auction, as a bidder sees it.
 *
 * EVERYTHING ON THIS PAGE IS COMPUTED ON THE SERVER. The countdown, the
 * standing to beat, the bid the viewer would place, the Buy Now price after
 * discount, whether the auction is open -- all of it is read from the database
 * on each render and re-read from scratch when a bid is submitted.
 *
 * THE BIDDER DOES NOT TYPE AN AMOUNT. Under the cumulative model there is
 * exactly one valid bid at any moment -- the credits that put the viewer one
 * step ahead of the leader -- and the server works it out. The page shows it on
 * a button; clicking asks for confirmation; confirming places it. What the
 * browser sends is the figure it was SHOWN, so the server can tell a stale page
 * from a fresh one, and the domain then re-derives the right figure under the
 * auction lock and refuses anything else. A refused bid consumes nothing, and a
 * figure that has moved is never quietly swapped for the new one: that would
 * spend more credits than the bidder agreed to.
 *
 * The countdown in particular is a number this component worked out and
 * handed over for display. It is refreshed by polling, and it is not what
 * decides anything: the auction ends when its `ends_at` timestamp says so,
 * whether or not any browser is watching.
 *
 * THE BID FORM DOES NOT SEND A PRICE. It cannot: there is no price on a bid.
 * A bid is a number of credits, and what those credits are worth is not a
 * question the interface is allowed to answer.
 *
 * BUY NOW OPENS A CHECKOUT; IT DOES NOT END THE AUCTION. The button creates an
 * order at a frozen price and sends the bidder to pay. The auction runs on,
 * other people keep bidding, and the standing highest bidder is still in the
 * running until a payment is verified server-side. Terminating on a click
 * would let an abandoned checkout kill a live auction.
 *
 * The winner's settlement works the same way: winning creates an obligation,
 * and paying it is what completes the sale.
 */
#[Layout('components.layouts.app')]
class AuctionRoom extends Component
{
    public Auction $auction;

    /**
     * How often the page re-reads the server, in seconds.
     *
     * A DISPLAY CADENCE, NEVER A BUSINESS RULE. None of these values can
     * change an outcome. An auction ends when its stored `ends_at` says so and
     * the sweep notices; a bid is validated against a locked auction row, not
     * against whatever the page last drew. Polling slowly means seeing a
     * change late -- it never means the change happened late.
     *
     * Three cadences because one number cannot fit both cases. An auction
     * three days from closing changes almost never, and polling it every five
     * seconds is 17,280 round trips per viewer per day for a number that did
     * not move. An auction inside its closing window changes constantly, and
     * that is exactly when a bidder needs an accurate figure in front of them.
     *
     * So the tail keeps the original five seconds unchanged, and everything
     * further out backs off.
     */
    public const POLL_CLOSING_SECONDS = 5;

    public const POLL_LIVE_SECONDS = 15;

    public const POLL_ENDED_SECONDS = 30;

    /**
     * How close to the end counts as "closing" for polling purposes.
     *
     * Applied alongside the auction's own closing window, so an auction whose
     * ruleset sets no window still tightens up before it ends.
     */
    public const POLL_TIGHTEN_WITHIN_SECONDS = 120;

    /**
     * The bid the viewer has been asked to confirm, in credits.
     *
     * Set only by {@see self::review()}, from the server's own reading, and
     * LOCKED: a request cannot write it. Even if it could, the domain would
     * refuse any figure that is not the one valid bid, so the lock is a second
     * wall rather than the only one.
     */
    #[Locked]
    public ?int $confirmedAmount = null;

    /**
     * One key per bid the customer intends to place.
     *
     * A double-tap, a flaky connection or an impatient refresh delivers the
     * same key, and the guard replays the first result instead of consuming
     * the credits again. It is regenerated only after a bid succeeds, which
     * is what makes the next bid a genuinely new one.
     */
    public string $bidKey = '';

    /**
     * Whether the bidder is looking at the confirmation.
     *
     * Committing credits is irreversible, so it takes two deliberate actions.
     * The figures shown on that confirmation are read from the server when it
     * opens, and read again from a locked auction row when the bid is actually
     * placed -- so a stale confirmation cannot commit credits against a state
     * that has moved on.
     */
    public bool $confirming = false;

    public function mount(Auction $auction): void
    {
        abort_unless($auction->status->isPubliclyVisible(), 404);

        $this->auction = $auction;

        // The room and its title read the product (name, description, link).
        // Loaded up front rather than lazily: route binding hands this
        // component a bare auction, and strict lazy-loading is on outside
        // production. Livewire re-hydrates this model from the recorded
        // snapshot on later requests, so loading once at mount is enough.
        $this->auction->loadMissing('product');

        $this->bidKey = (string) Str::uuid();
    }

    /**
     * Ask the bidder to confirm the bid they were shown.
     *
     * `$shown` is the figure on the button they pressed. It is compared with
     * the server's reading of the bid right now, so a page that has gone stale
     * says so here -- with the new figure -- rather than quietly opening a
     * confirmation for a different amount than the one they clicked.
     *
     * This cannot approve a bid. Every business rule is the domain's, checked
     * against a locked auction row when the bid is placed, so a confirmation
     * that has been open for a while cannot make a bid valid that no longer is.
     */
    public function review(BidValidator $validator, int $shown): void
    {
        $this->authorize('bids.place');
        $this->resetErrorBag();

        $auction = $this->auction->fresh();

        // Only the cumulative model is bid on here. There is no free-text
        // amount to fall back to: an auction that follows the earlier rules
        // is shown, and not bid on, through this page.
        if (! $auction->rules()->bidModel->isCumulative()) {
            $this->addError('bid', 'This auction is not taking new bids.');

            return;
        }

        $next = $validator->nextBid($auction, auth()->user());

        if ($next === null) {
            $this->auction->refresh();
            $this->addError('bid', 'You already hold the lead. You can bid again once somebody overtakes you.');

            return;
        }

        if ($next !== $shown) {
            $this->auction->refresh();
            $this->addError('bid', 'Somebody bid while you were looking. To take the lead you now need to add '.CreditAmount::fromSubcredits($next)->format().'.');

            return;
        }

        $this->confirmedAmount = $next;
        $this->confirming = true;
    }

    public function cancelBid(): void
    {
        $this->confirming = false;
        $this->confirmedAmount = null;
    }

    /**
     * Commit credits to this auction.
     *
     * Places the figure the bidder confirmed, and nothing else. The domain
     * re-derives the one valid bid against a freshly locked auction row and
     * either accepts that figure or refuses it. Nothing this component
     * displayed is trusted at that point -- the page may be seconds old, and
     * someone else may have bid, bought the product, or ended the auction
     * since it was rendered.
     */
    public function bid(PlaceBid $placeBid): void
    {
        $this->authorize('bids.place');

        if ($this->confirmedAmount === null) {
            // Nothing was confirmed: the confirmation was cancelled, or this
            // call did not come from the page. There is no amount to place.
            $this->confirming = false;

            return;
        }

        try {
            $placeBid->handle(
                auction: $this->auction->fresh(),
                user: auth()->user(),
                amountCredits: $this->confirmedAmount,
                idempotencyKey: $this->bidKey,
            );
        } catch (BidRejected $e) {
            // The domain's own words. They say what the rule is and what would
            // satisfy it, which is more use to someone who just tried to spend
            // credits than a generic failure.
            //
            // The confirmation closes and the page re-reads the auction: the
            // usual reason a bid is refused is that somebody else bid first,
            // and the figure the bidder agreed to is no longer the right one.
            // NOTHING was consumed, and the next click asks again with the
            // figure that is right now -- under a fresh key, because a refused
            // attempt and its retry are two different intended bids.
            $this->confirming = false;
            $this->confirmedAmount = null;
            $this->bidKey = (string) Str::uuid();
            $this->auction->refresh();
            $this->addError('bid', $e->getMessage());

            return;
        } catch (ConcurrentOperationInProgress) {
            $this->addError('bid', 'That bid is still being processed. Give it a moment.');

            return;
        }

        $this->auction->refresh();
        $this->confirmedAmount = null;
        $this->confirming = false;
        $this->bidKey = (string) Str::uuid();

        session()->flash('bid-placed', 'Your bid was accepted and the credits have been consumed.');
    }

    /**
     * Open a checkout to buy this product outright.
     *
     * Creates an order at a price the server computes and freezes, then sends
     * the customer to review and pay it. Nothing about the auction changes
     * here: it is still live, still taking bids, and still winnable by the
     * highest bidder until a payment is actually verified.
     */
    public function buyNow(StartBuyNowCheckout $checkout): ?RedirectResponse
    {
        $this->authorize('checkout.create');

        try {
            $order = $checkout->handle(
                buyer: auth()->user(),
                product: $this->auction->product,
                auction: $this->auction->fresh(),
            );
        } catch (DomainException $e) {
            $this->addError('checkout', $e->getMessage());
            $this->auction->refresh();

            return null;
        }

        return redirect()->route('checkout.show', $order);
    }

    /**
     * Open the checkout this auction's winner settles through.
     *
     * Refused for anybody but the winner, in the domain rather than here: who
     * won is not a question the interface gets to answer.
     */
    public function settle(StartSettlementCheckout $checkout): ?RedirectResponse
    {
        $this->authorize('checkout.create');

        try {
            $order = $checkout->handle($this->auction->fresh(), auth()->user());
        } catch (InvalidCheckout $e) {
            $this->addError('checkout', $e->getMessage());

            return null;
        }

        return redirect()->route('checkout.show', $order);
    }

    /**
     * Everything the page displays, read once.
     *
     * WHY THIS IS ONE METHOD. Each of these facts used to be a public method
     * the template called wherever it needed the answer, and Blade called
     * several of them more than once -- `viewerCommittedCredits()` three
     * times, `myBids()` in four branches. Every call was another query, on a
     * page that polls every five seconds for every viewer watching the
     * auction. Reading each fact once and handing the answers to the template
     * is the whole optimization.
     *
     * NOTHING IS CACHED BETWEEN REQUESTS. These are read fresh on every poll,
     * from the same authoritative queries as before. What changed is how many
     * times one render asks, not how long an answer is trusted.
     *
     * @return array<string, mixed>
     */
    private function viewFor(HighestBidResolver $bids, AuctionClock $clock, BuyNowPricer $pricer, BidValidator $validator, CreditLedgerService $credits): array
    {
        $auction = $this->auction;
        $viewer = auth()->user();

        // The authoritative reading, not the cached projection: this is the
        // number the page is about, and everything below is measured against
        // it rather than against a second, weaker read.
        $highestBid = $bids->highestBid($auction);

        // This viewer's own bids, fetched once. The three places that used to
        // sum them separately now read `committedCredits` from this same
        // collection -- identical figure, no extra query.
        $myBids = $viewer === null
            ? collect()
            : $auction->bids()->where('user_id', $viewer->id)->orderByDesc('sequence')->get();

        $committedCredits = (int) $myBids->sum('amount_credits');

        // From the authoritative bid rather than `$auction->highestBid`, which
        // would lazy-load the projection: a second query for a less
        // authoritative answer to a question already answered above.
        $isLeading = $viewer !== null && $highestBid?->user_id === $viewer->id;

        return [
            // The winner's checkout, opened when the auction closed. Shown so
            // they can reach it from here rather than hunting for it while a
            // deadline runs.
            'settlementOrder' => $viewer === null
                ? null
                : $auction->settlementOrder()->where('user_id', $viewer->id)->first(),

            'highestBid' => $highestBid,
            'history' => $bids->history($auction, 25),

            // Anonymised participant numbers, computed across the whole
            // auction rather than the 25 rows shown -- so a bidder keeps one
            // number however far the history scrolls, and one person bidding
            // three times reads as one person rather than three.
            'participants' => $bids->participantNumbers($auction),

            // Worked out on the server. A client that reports time remaining
            // is reporting an opinion.
            'secondsRemaining' => $clock->secondsRemaining($auction),
            'latestPossibleEnd' => $clock->latestPossibleEnd($auction),

            'myBids' => $myBids,
            'committedCredits' => $committedCredits,
            'viewerIsLeading' => $isLeading,

            // Bid and been overtaken. The page says so, and says what it would
            // now take -- and nothing about who overtook them.
            'viewerIsOutbid' => $viewer !== null
                && $auction->status->acceptsBids()
                && ! $isLeading
                && $committedCredits > 0,

            // Bid and did not win. A losing bidder is owed a straight answer,
            // and their credits stay consumed -- which the page also says.
            'viewerLost' => $viewer !== null
                && $auction->hasEnded()
                && $auction->winner_user_id !== $viewer->id
                && $myBids->isNotEmpty(),

            // The model this auction follows, frozen into its snapshot. Every
            // rule and label the page states comes from here, so a page never
            // describes one model while the auction follows the other.
            'bidModel' => $auction->rules()->bidModel,

            // The ONE bid this viewer could place right now, worked out by the
            // server: the credits that put them a step ahead of the leader.
            // Null when they hold the lead and have nothing to place. It is for
            // display; the domain re-derives it under the lock when it matters.
            'nextBid' => $validator->nextBid($auction, $viewer),

            // How far ahead of the leader every bid lands, from the frozen rules.
            'stepCredits' => $auction->rules()->bidIncrementCredits,

            'buyNowQuote' => $pricer->quote($auction, $viewer),

            'spendableBalance' => $viewer === null
                ? 0
                : $credits->walletFor($viewer)->spendableBalance(),

            // Only for an auction that carries one (docs/PLAN_POT_TARGET_BIDDING.md,
            // step 5) -- null for every auction until step 6 gives an
            // administrator a field for it, so this costs an ordinary page
            // render nothing extra. Everybody's bids added together, never
            // one bidder's own total -- committedCredits above answers that
            // question.
            'potTargetCredits' => $auction->pot_target_credits,
            'potTotal' => $auction->pot_target_credits === null ? null : $bids->potTotal($auction),

            'pollSeconds' => $this->pollSeconds($clock),
        ];
    }

    /**
     * How often this page should ask again.
     *
     * Worked out on the server, from the clock, like everything else the
     * browser is handed. Nothing about correctness depends on the answer: it
     * decides how soon a change is *seen*, never whether it happened.
     */
    private function pollSeconds(AuctionClock $clock): int
    {
        if (! $this->auction->status->acceptsBids()) {
            // Ended, or not yet open. Still worth asking -- a settlement
            // checkout or a status change should appear without a reload --
            // but there is no bidding to keep up with.
            return self::POLL_ENDED_SECONDS;
        }

        $remaining = $clock->secondsRemaining($this->auction);

        if ($remaining === null) {
            return self::POLL_LIVE_SECONDS;
        }

        if ($clock->isInClosingWindow($this->auction) || $remaining <= self::POLL_TIGHTEN_WITHIN_SECONDS) {
            return self::POLL_CLOSING_SECONDS;
        }

        return self::POLL_LIVE_SECONDS;
    }

    public function render(
        HighestBidResolver $bids,
        AuctionClock $clock,
        BuyNowPricer $pricer,
        BidValidator $validator,
        CreditLedgerService $credits,
    ): View {
        // No `refresh()` here. Livewire re-reads this model from the database
        // when it hydrates the component, so refreshing again was a second
        // query for a row that had just been fetched. The actions that change
        // the auction refresh it themselves, where it actually matters.
        return view(
            'livewire.auctions.auction-room',
            $this->viewFor($bids, $clock, $pricer, $validator, $credits),
        )->title($this->auction->product->name);
    }
}

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
use Livewire\Component;

/**
 * One auction, as a bidder sees it.
 *
 * EVERYTHING ON THIS PAGE IS COMPUTED ON THE SERVER. The countdown, the
 * standing highest bid, the smallest valid bid, the Buy Now price after
 * discount, whether the auction is open -- all of it is read from the database
 * on each render and re-read from scratch when a bid is submitted. The browser
 * sends one thing: how many credits the bidder wants to commit.
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
     * Held as a string so an empty field stays empty rather than becoming
     * zero, and so nothing is coerced before validation sees it.
     */
    public string $amount = '';

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
        $this->bidKey = (string) Str::uuid();
    }

    /**
     * Show the bidder exactly what they are about to do.
     *
     * Validates the shape of the amount only. Every business rule is the
     * domain's, checked against a locked auction row when the bid is placed --
     * so this cannot approve a bid, and a confirmation that has been open for
     * a while cannot make one valid that no longer is.
     */
    public function review(): void
    {
        $this->authorize('bids.place');

        $this->validate([
            'amount' => ['required', 'regex:/^\d{1,12}$/'],
        ], [
            'amount.regex' => 'Enter a whole number of credits.',
        ]);

        $this->confirming = true;
    }

    public function cancelBid(): void
    {
        $this->confirming = false;
    }

    /**
     * Commit credits to this auction.
     *
     * The amount is validated for shape here and for every business rule by
     * the domain, against a freshly locked auction row. Nothing this component
     * displayed is trusted at that point -- the page may be seconds old, and
     * someone else may have bid, bought the product, or ended the auction
     * since it was rendered.
     */
    public function bid(PlaceBid $placeBid): void
    {
        $this->authorize('bids.place');

        $validated = $this->validate([
            // A whole number of credits. Not money, so no decimal point.
            'amount' => ['required', 'regex:/^\d{1,12}$/'],
        ], [
            'amount.regex' => 'Enter a whole number of credits.',
        ]);

        try {
            $placeBid->handle(
                auction: $this->auction->fresh(),
                user: auth()->user(),
                amountCredits: (int) $validated['amount'],
                idempotencyKey: $this->bidKey,
            );
        } catch (BidRejected $e) {
            // The domain's own words. They say what the rule is and what would
            // satisfy it, which is more use to someone who just tried to spend
            // credits than a generic failure.
            //
            // The confirmation closes and the page re-reads the auction: the
            // usual reason a bid is refused is that somebody else bid first,
            // and the figures the bidder was looking at are now wrong.
            $this->confirming = false;
            $this->auction->refresh();
            $this->addError('amount', $e->getMessage());

            return;
        } catch (ConcurrentOperationInProgress) {
            $this->addError('amount', 'That bid is still being processed. Give it a moment.');

            return;
        }

        $this->auction->refresh();
        $this->amount = '';
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

            // The smallest bid that would be valid right now. Null when this
            // auction sets no floor at all, which is a real answer and not a
            // missing one: an unset rule is not a rule of one credit.
            'smallestValidBid' => $validator->smallestValidBid($auction),

            'buyNowQuote' => $pricer->quote($auction, $viewer),

            'spendableBalance' => $viewer === null
                ? 0
                : $credits->walletFor($viewer)->spendableBalance(),

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

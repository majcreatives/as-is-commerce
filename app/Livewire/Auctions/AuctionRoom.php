<?php

declare(strict_types=1);

namespace App\Livewire\Auctions;

use App\Domain\Auction\Actions\PlaceBid;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Auction\Services\BidValidator;
use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Auction\ValueObjects\BuyNowQuote;
use App\Domain\Shared\Idempotency\ConcurrentOperationInProgress;
use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Support\Collection;
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
 * BUY NOW IS QUOTED, NOT SOLD. The page shows what buying outright would cost
 * this bidder after their consumed credits are taken off, and stops there.
 * Completing a purchase needs a payment, and payments belong to a later stage
 * -- wiring a button straight to the termination action would be fabricating
 * a payment that never happened.
 */
#[Layout('components.layouts.app')]
class AuctionRoom extends Component
{
    public Auction $auction;

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

    public function mount(Auction $auction): void
    {
        abort_unless($auction->status->isPubliclyVisible(), 404);

        $this->auction = $auction;
        $this->bidKey = (string) Str::uuid();
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
            $this->addError('amount', $e->getMessage());

            return;
        } catch (ConcurrentOperationInProgress) {
            $this->addError('amount', 'That bid is still being processed. Give it a moment.');

            return;
        }

        $this->auction->refresh();
        $this->amount = '';
        $this->bidKey = (string) Str::uuid();

        session()->flash('bid-placed', 'Your bid was accepted and the credits have been consumed.');
    }

    /**
     * Seconds left, worked out here rather than in the browser.
     */
    public function secondsRemaining(AuctionClock $clock): ?int
    {
        return $clock->secondsRemaining($this->auction);
    }

    /**
     * The smallest bid that would be valid right now.
     *
     * Null when this auction sets no floor at all, which is a real answer and
     * not a missing one: an unset rule is not a rule of one credit.
     */
    public function smallestValidBid(BidValidator $validator): ?int
    {
        return $validator->smallestValidBid($this->auction);
    }

    public function buyNowQuote(BuyNowPricer $pricer): BuyNowQuote
    {
        return $pricer->quote($this->auction, auth()->user());
    }

    /**
     * This bidder's own bids on this auction.
     *
     * @return Collection<int, Bid>
     */
    public function myBids(): Collection
    {
        if (! auth()->check()) {
            return collect();
        }

        return $this->auction->bids()
            ->where('user_id', auth()->id())
            ->orderByDesc('sequence')
            ->get();
    }

    public function render(HighestBidResolver $bids, AuctionClock $clock): View
    {
        $this->auction->refresh();

        return view('livewire.auctions.auction-room', [
            // The authoritative reading, not the cached projection: this is
            // the number the page is about.
            'highestBid' => $bids->highestBid($this->auction),
            'history' => $bids->history($this->auction, 25),
            'secondsRemaining' => $clock->secondsRemaining($this->auction),
            'latestPossibleEnd' => $clock->latestPossibleEnd($this->auction),
        ])->title($this->auction->product->name);
    }
}

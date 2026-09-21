<?php

declare(strict_types=1);

namespace App\Domain\Auction\Services;

use App\Domain\Auction\Actions\PlaceBid;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Enums\UserStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Everything that stands between a bid attempt and a recorded bid.
 *
 * THE SERVER DECIDES. Nothing the browser sends is trusted: not the auction's
 * status, not the countdown, not the standing highest bid, not the minimum
 * valid amount, not the wallet balance. Every one of those is read here, from
 * the database, at the moment of the attempt. The page a bidder is looking at
 * may be seconds out of date, and acting on what it says is exactly how two
 * people both believe they bid validly.
 *
 * CALLED UNDER LOCK. {@see PlaceBid} holds the
 * auction row before calling this, and the highest-bid reads here are locking
 * reads. Validating first and locking afterwards would let two simultaneous
 * bids both measure themselves against the same standing highest bid, and both
 * satisfy an increment rule only one of them actually clears.
 *
 * RULES THAT ARE NOT SET DO NOT APPLY. The minimum bid, the increment and the
 * bid-increase rule are each nullable in the frozen snapshot, and null means
 * "no rule". Nothing here substitutes a default -- inventing a floor of one
 * credit would be inventing a business decision nobody made.
 */
class BidValidator
{
    public function __construct(
        private readonly AuctionClock $clock,
        private readonly HighestBidResolver $bids,
        private readonly CreditLedgerService $credits,
    ) {}

    /**
     * Refuse the bid, with a reason, or return quietly.
     *
     * Order matters: the cheap structural checks run before the ones that
     * touch the wallet, so an obviously invalid attempt never reaches the
     * ledger. A refusal here means nothing has happened -- the caller's
     * transaction has written nothing yet.
     *
     * @throws BidRejected
     */
    public function assertValid(
        Auction $auction,
        User $user,
        int $amountCredits,
        ?Carbon $now = null,
    ): void {
        $now ??= Carbon::now();

        $this->assertAuctionAcceptingBids($auction, $now);
        $this->assertUserEligible($user);
        $this->assertAmountWellFormed($amountCredits);
        $this->assertAmountSatisfiesRules($auction, $user, $amountCredits);
        $this->assertNotTooSoon($auction, $user, $now);
        $this->assertCreditsAvailable($user, $amountCredits);
    }

    /**
     * The auction exists, is open, and its clock has not run out.
     *
     * The clock is checked separately from the status because they can
     * disagree: an auction past its end time stays marked Live until the
     * closing sweep notices. A bid arriving in that gap must be refused, not
     * accepted because a column has not caught up yet.
     */
    private function assertAuctionAcceptingBids(Auction $auction, Carbon $now): void
    {
        if (! $auction->status->acceptsBids()) {
            // Being explicit about the Buy Now case: someone bought the
            // product while this bidder was deciding, and telling them the
            // auction is "closed" would not explain why.
            if ($auction->endedByBuyNow()) {
                throw BidRejected::because(
                    'This auction ended because the product was bought outright.'
                );
            }

            throw BidRejected::notOpen($auction->status->label());
        }

        if ($auction->starts_at === null || $now->lessThan($auction->starts_at)) {
            throw BidRejected::notStarted();
        }

        if ($this->clock->hasExpired($auction, $now)) {
            throw BidRejected::alreadyEnded();
        }
    }

    /**
     * The bidder is allowed to take part.
     */
    private function assertUserEligible(User $user): void
    {
        if ($user->status !== UserStatus::Active) {
            throw BidRejected::because('Your account cannot place bids at the moment.');
        }

        if (! $user->can('bids.place')) {
            throw BidRejected::because('You are not permitted to bid.');
        }
    }

    /**
     * A bid commits a whole, positive number of credits.
     *
     * The type system already guarantees an integer; this catches zero and
     * negatives, which would otherwise reach the ledger as a credit rather
     * than a debit.
     */
    private function assertAmountWellFormed(int $amountCredits): void
    {
        if ($amountCredits <= 0) {
            throw BidRejected::amountNotPositive($amountCredits);
        }
    }

    /**
     * The amount satisfies whichever bid rules the auction actually has.
     *
     * Which rules those are is decided by the bid model frozen into the
     * auction's snapshot, never by anything else.
     */
    private function assertAmountSatisfiesRules(Auction $auction, User $user, int $amountCredits): void
    {
        $rules = $auction->rules();

        if ($rules->bidModel->isCumulative()) {
            $this->assertCatchUpBid($auction, $user, $amountCredits);

            return;
        }

        $highest = $this->bids->highestBidForUpdate($auction);

        if ($rules->hasMinimumBid() && $amountCredits < $rules->minimumBidCredits) {
            throw BidRejected::belowMinimum($amountCredits, (int) $rules->minimumBidCredits);
        }

        if ($highest === null) {
            // No standing bid: the increment has nothing to apply to, and
            // nobody can be raising their own bid.
            return;
        }

        // Raising your own bid, when the rules forbid it. Checked before the
        // increment so the bidder is told the real reason rather than being
        // shown a floor they are not allowed to clear anyway.
        if ($rules->allowBidIncrease === false && $highest->user_id === $user->id) {
            throw BidRejected::increasesOwnBid($highest->amount_credits);
        }

        if ($rules->hasMinimumIncrement()) {
            $required = $highest->amount_credits + (int) $rules->minimumBidIncrementCredits;

            if ($amountCredits < $required) {
                throw BidRejected::belowIncrement($amountCredits, $required, $highest->amount_credits);
            }
        }
    }

    /**
     * The cumulative model: the bid must be EXACTLY the one that lands the
     * bidder one step ahead of the leader.
     *
     * The bidder does not choose the amount, and this is where that is
     * enforced rather than merely displayed. It runs under the auction row lock
     * against a locking read of the leader, so two bidders who each worked out
     * the same catch-up bid from the same leader cannot both clear it: the
     * first commits, and the second reads the leader the first left behind and
     * finds their amount is no longer the right one. That refusal consumes
     * nothing, and the message names the figure that is right now.
     *
     * Order matters. A leader is told they already lead before being shown an
     * amount they could never place, and the opening bid -- the only bid with
     * no leader to measure against -- is the minimum and nothing else.
     */
    private function assertCatchUpBid(Auction $auction, User $user, int $amountCredits): void
    {
        $rules = $auction->rules();
        $leader = $this->bids->highestBidForUpdate($auction);

        if ($leader === null) {
            $opening = $rules->catchUpBid(null);

            if ($amountCredits !== $opening) {
                throw BidRejected::notTheOpeningBid($amountCredits, $opening);
            }

            return;
        }

        if ($leader->user_id === $user->id) {
            throw BidRejected::leaderCannotBid($leader->rankingValue());
        }

        $mine = $this->bids->standingOf($auction, $user->id);
        $expected = $rules->catchUpBid($leader->rankingValue(), $mine);

        if ($amountCredits !== $expected) {
            throw BidRejected::notTheCatchUpBid($amountCredits, $expected, $leader->rankingValue(), $mine);
        }
    }

    /**
     * The throttle, if the auction has one.
     *
     * Per bidder rather than per auction: the rule exists to stop one person
     * hammering the endpoint, not to stop a busy auction from being busy.
     */
    private function assertNotTooSoon(Auction $auction, User $user, Carbon $now): void
    {
        $interval = $auction->rules()->minimumBidIntervalMs;

        if ($interval <= 0) {
            return;
        }

        $last = Bid::query()
            ->where('auction_id', $auction->getKey())
            ->where('user_id', $user->id)
            ->orderByDesc('sequence')
            ->first();

        if ($last === null) {
            return;
        }

        $elapsed = $last->created_at->diffInMilliseconds($now, false);

        if ($elapsed < $interval) {
            throw BidRejected::tooSoon((int) ceil($interval - $elapsed));
        }
    }

    /**
     * The bidder has the credits this bid would consume.
     *
     * Read from the wallet, never from anything the browser sent. This is a
     * pre-check for a clear error message: the authoritative refusal happens
     * inside the ledger, which locks the wallet and recomputes availability
     * from the credit lots themselves.
     */
    private function assertCreditsAvailable(User $user, int $amountCredits): void
    {
        $wallet = $this->credits->walletFor($user);

        if ($wallet->balance < $amountCredits) {
            throw BidRejected::because(
                "This bid needs {$amountCredits} credits and your balance is {$wallet->balance}."
            );
        }
    }

    /**
     * The smallest amount that would be valid right now, or null if the
     * auction sets no floor at all.
     *
     * Computed on the server and sent to the browser to display. The browser
     * never computes it, and a bid is validated against a fresh reading of
     * this rather than against whatever was rendered.
     */
    public function smallestValidBid(Auction $auction): ?int
    {
        // Null under the cumulative model, by the rules object's own answer:
        // there is exactly one valid bid, it depends on who is asking, and it
        // is {@see self::nextBid()}.
        return $auction->rules()->smallestValidBid($this->bids->highestAmount($auction));
    }

    /**
     * The one bid this person could place right now under the cumulative model,
     * or null when there is none to show them.
     *
     * FOR DISPLAY. This is an unlocked read, made for a page to show, and it is
     * never what decides a bid: {@see self::assertValid()} re-derives the figure
     * under the auction lock at the moment of placing, and refuses anything else.
     * A page that is seconds out of date shows a number that is slightly wrong,
     * and the domain, not the page, is what says so.
     *
     * Null means "no bid to offer": the auction is not cumulative, or this person
     * holds the lead and has nothing to place until they are overtaken.
     *
     * A visitor who is not signed in is measured as having placed nothing, so a
     * guest sees the cost of joining: the leader's total plus one step.
     */
    public function nextBid(Auction $auction, ?User $user): ?int
    {
        $rules = $auction->rules();

        if (! $rules->bidModel->isCumulative()) {
            return null;
        }

        $leader = $this->bids->highestBid($auction);

        if ($leader === null) {
            return $rules->catchUpBid(null);
        }

        if ($user !== null && $leader->user_id === $user->id) {
            return null;
        }

        $mine = $user === null ? 0 : $this->bids->standingOf($auction, $user->id);

        return $rules->catchUpBid($leader->rankingValue(), $mine);
    }
}

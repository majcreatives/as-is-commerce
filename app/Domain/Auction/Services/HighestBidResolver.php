<?php

declare(strict_types=1);

namespace App\Domain\Auction\Services;

use App\Models\Auction;
use App\Models\Bid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The authority on which bid is highest.
 *
 * THE RULE. The leading bid is the one with the largest `amount_credits`. Not
 * the most recent, not the most frequent bidder, not the largest total
 * committed across several bids -- a single bid's amount, and nothing else.
 * A bidder who bids 20, is overtaken by 100, and later bids 150 leads on that
 * 150.
 *
 * THE TIE-BREAK. Two users can commit the same highest amount, and "the
 * highest bid wins" has to name one of them. Among equal amounts the earliest
 * accepted bid leads, ordered by the sequence number allocated under the
 * auction row lock rather than by a timestamp -- two bids in the same
 * millisecond would otherwise be genuinely ambiguous.
 *
 * This does not change the winner rule. The winner is still the highest valid
 * credit bid; the tie-break only makes equal values deterministic, so the
 * same question always gets the same answer.
 *
 * THE PROJECTION. `auctions.highest_bid_id`, `highest_bid_credits` and
 * `bid_count` cache what these queries return, so a listing page does not
 * aggregate the bid table once per row. They are a cache and never a source
 * of truth: this class is the only code permitted to write them, it always
 * writes what it just read from the bid records, and {@see self::rebuild()}
 * recomputes them from scratch. If the two ever disagree, the bids are right.
 */
class HighestBidResolver
{
    /**
     * The leading bid, straight from the bid records.
     *
     * This is the authoritative answer. Callers deciding a winner use it;
     * callers rendering a page may use the cached projection instead.
     */
    public function highestBid(Auction $auction): ?Bid
    {
        return Bid::query()
            ->where('auction_id', $auction->getKey())
            ->counting()
            ->leadingFirst()
            ->first();
    }

    /**
     * The leading amount in credits, or null when nobody has bid.
     *
     * Null rather than zero: no bids at all is a different state from a bid
     * of nothing, and a floor computed from zero would be wrong.
     */
    public function highestAmount(Auction $auction): ?int
    {
        $amount = Bid::query()
            ->where('auction_id', $auction->getKey())
            ->counting()
            ->max('amount_credits');

        return $amount === null ? null : (int) $amount;
    }

    /**
     * The leading bid, read under the auction's own row lock.
     *
     * Used inside bid placement, where the caller already holds the auction
     * row. Reading without that lock would let two simultaneous bids both
     * measure themselves against the same standing highest bid and both
     * satisfy an increment rule that only one of them actually clears.
     */
    public function highestBidForUpdate(Auction $auction): ?Bid
    {
        return Bid::query()
            ->where('auction_id', $auction->getKey())
            ->counting()
            ->leadingFirst()
            ->lockForUpdate()
            ->first();
    }

    /**
     * That user's highest bid on this auction, if any.
     *
     * Answers "am I already leading?", which the bid-increase rule needs.
     */
    public function highestBidBy(Auction $auction, int $userId): ?Bid
    {
        return Bid::query()
            ->where('auction_id', $auction->getKey())
            ->where('user_id', $userId)
            ->counting()
            ->leadingFirst()
            ->first();
    }

    /**
     * Credits this user has consumed bidding on this auction.
     *
     * The sum of their accepted bids on this auction and nothing else. Not
     * their wallet balance, not credits they bought, not credits they spent on
     * a different auction -- those are all different quantities, and only this
     * one is what the Buy Now discount is allowed to be based on.
     *
     * Derived from the bid records, which are chained one-to-one to the credit
     * transactions that consumed the credits. It is therefore a historical
     * fact rather than a function of a mutable balance, and it cannot change
     * when the user spends credits elsewhere.
     */
    public function consumedCreditsBy(Auction $auction, int $userId): int
    {
        return (int) Bid::query()
            ->where('auction_id', $auction->getKey())
            ->where('user_id', $userId)
            ->counting()
            ->sum('amount_credits');
    }

    /**
     * How many bids this auction has taken.
     */
    public function bidCount(Auction $auction): int
    {
        return Bid::query()
            ->where('auction_id', $auction->getKey())
            ->counting()
            ->count();
    }

    /**
     * The bid history, leading first.
     *
     * THE BIDDER IS NOT LOADED BY DEFAULT. A customer-facing history names
     * nobody, so the identity it would need to hide is simply not fetched --
     * `user_id` is on the bid row, which is all a page needs to recognise the
     * viewer's own bids. Staff screens pass `withBidder: true`, because an
     * administrator is entitled to see who bid and an N+1 is the only other
     * way to show them.
     *
     * @return Collection<int, Bid>
     */
    public function history(Auction $auction, int $limit = 50, bool $withBidder = false): Collection
    {
        return Bid::query()
            ->where('auction_id', $auction->getKey())
            ->when($withBidder, fn (Builder $q) => $q->with('user'))
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get();
    }

    /**
     * Which participant each bidder is, numbered by when they first bid.
     *
     * A PARTICIPANT, NOT A BID. The first person to bid on this auction is
     * participant 1 for the life of it, however many times they bid afterwards
     * and however many other people bid in between. Numbering by the bid's own
     * `sequence` instead -- which is what a customer-facing history used to do
     * -- gives one person a new number on every bid, so three bids from one
     * bidder read as three different people competing.
     *
     * COMPUTED OVER THE WHOLE AUCTION. Ranking within a page of history would
     * renumber everybody as the list scrolled, and the same bid would carry
     * different numbers on the customer page and the admin page. The map is
     * one grouped row per bidder, so its size is bounded by how many distinct
     * people have bid.
     *
     * The status filter deliberately matches {@see self::history()} rather
     * than `counting()`: this map exists to number the rows that history
     * returns, and a row it did not cover would render as a bidder with no
     * number.
     *
     * NOT AN IDENTITY. The number says only "these bids came from the same
     * person". It is per auction, so the same bidder is a different number on
     * a different auction and nothing can be correlated across the two.
     *
     * @return array<int, int> Participant number, keyed by user id.
     */
    public function participantNumbers(Auction $auction): array
    {
        $firstBidPerUser = Bid::query()
            ->where('auction_id', $auction->getKey())
            ->selectRaw('user_id, MIN(sequence) as first_sequence')
            ->groupBy('user_id')
            ->orderBy('first_sequence')
            ->pluck('first_sequence', 'user_id');

        $numbers = [];
        $next = 1;

        foreach ($firstBidPerUser as $userId => $firstSequence) {
            $numbers[(int) $userId] = $next++;
        }

        return $numbers;
    }

    /**
     * Recompute the cached projection from the bid records.
     *
     * The whole point of a rebuildable projection: if these columns are ever
     * wrong -- a bug, a hand-run statement, a restore -- this puts them right
     * without anyone having to work out what they should have been.
     *
     * Callers inside bid placement already hold the auction row lock, so the
     * read and the write cannot interleave with another bid.
     */
    public function rebuild(Auction $auction): Auction
    {
        $highest = $this->highestBid($auction);
        $count = $this->bidCount($auction);

        Auction::permittingHighestBidWrites(function () use ($auction, $highest, $count): void {
            $auction->highest_bid_id = $highest?->id;
            $auction->highest_bid_credits = $highest?->amount_credits;
            $auction->bid_count = $count;
            $auction->save();
        });

        return $auction;
    }

    /**
     * Whether the cached projection matches the bid records.
     *
     * Reports; it never repairs. Deciding to overwrite stored state is a
     * separate act from noticing it is wrong, and conflating the two hides
     * the fact that it happened.
     *
     * @return array{matches: bool, projected_highest_bid_id: int|null, actual_highest_bid_id: int|null, projected_bid_count: int, actual_bid_count: int}
     */
    public function verify(Auction $auction): array
    {
        $highest = $this->highestBid($auction);
        $count = $this->bidCount($auction);

        return [
            'matches' => $auction->highest_bid_id === $highest?->id
                && $auction->highest_bid_credits === $highest?->amount_credits
                && $auction->bid_count === $count,
            'projected_highest_bid_id' => $auction->highest_bid_id,
            'actual_highest_bid_id' => $highest?->id,
            'projected_bid_count' => $auction->bid_count,
            'actual_bid_count' => $count,
        ];
    }

    /**
     * The next sequence number for this auction.
     *
     * Allocated under the auction row lock the caller already holds, which is
     * what makes it collision-free without a separate counter. The unique
     * index on (auction_id, sequence) is the backstop if that lock is ever
     * missing.
     */
    public function nextSequence(Auction $auction): int
    {
        $highest = Bid::query()
            ->where('auction_id', $auction->getKey())
            ->max('sequence');

        return $highest === null ? 1 : ((int) $highest) + 1;
    }
}

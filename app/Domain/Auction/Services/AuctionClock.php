<?php

declare(strict_types=1);

namespace App\Domain\Auction\Services;

use App\Domain\Auction\ValueObjects\AuctionRules;
use App\Models\Auction;
use Illuminate\Support\Carbon;

/**
 * When an auction runs, and when it stops.
 *
 * THE DATABASE IS THE CLOCK. `starts_at` and `ends_at` decide everything here.
 * No browser countdown, JavaScript timer, session or PHP process lifetime has
 * any bearing on it. A bidder closing their laptop, a server restarting, or a
 * page left open for three days changes nothing: the next request reads the
 * same two timestamps and reaches the same conclusion.
 *
 * The interface may display a countdown, but it is a rendering of a number
 * this class computed on the server. A client that reports time remaining is
 * reporting an opinion.
 *
 * TIMING DOES NOT PICK THE WINNER. Everything in this class decides *when* an
 * auction ends. Who wins is decided from the bid records, by amount. Late-bid
 * extension exists to stop sniping -- it gives everyone else a chance to bid
 * higher -- and it cannot change who is winning at any point, because being
 * last has no meaning under this model.
 *
 * All times are UTC. Ghana time is a presentation concern.
 */
class AuctionClock
{
    /**
     * When an auction starting now would end.
     */
    public function endFor(AuctionRules $rules, Carbon $startsAt): Carbon
    {
        return $startsAt->copy()->addSeconds($rules->baseDurationSeconds);
    }

    /**
     * Whether the auction's clock has run out.
     *
     * Independent of status: an auction can be past its end time and still be
     * marked Live, because something has to notice. That gap is exactly what
     * the closing sweep exists to close, and it is why bid placement checks
     * the clock as well as the status.
     */
    public function hasExpired(Auction $auction, ?Carbon $now = null): bool
    {
        if ($auction->ends_at === null) {
            return false;
        }

        return ($now ?? Carbon::now())->greaterThanOrEqualTo($auction->ends_at);
    }

    /**
     * Whether the auction's scheduled start time has arrived.
     */
    public function isDueToStart(Auction $auction, ?Carbon $now = null): bool
    {
        if ($auction->scheduled_start_at === null) {
            return false;
        }

        return ($now ?? Carbon::now())->greaterThanOrEqualTo($auction->scheduled_start_at);
    }

    /**
     * Seconds until the auction ends, floored at zero.
     *
     * Computed here, on the server, and sent to the browser as a number to
     * display. Null when the auction has no end time yet.
     */
    public function secondsRemaining(Auction $auction, ?Carbon $now = null): ?int
    {
        if ($auction->ends_at === null) {
            return null;
        }

        $now ??= Carbon::now();

        // Cast explicitly: Carbon returns a float difference, and a countdown
        // is a whole number of seconds. Truncating towards zero understates
        // rather than overstates the time left, which is the right way round
        // for a deadline.
        return max(0, (int) $now->diffInSeconds($auction->ends_at, false));
    }

    /**
     * Whether the auction is inside its closing window.
     *
     * The window is the tail of the auction in which a bid may extend the
     * clock. With a window of zero there is no such tail, which is a valid
     * configuration: the auction simply ends when it ends.
     */
    public function isInClosingWindow(Auction $auction, ?Carbon $now = null): bool
    {
        $remaining = $this->secondsRemaining($auction, $now);
        $window = $auction->rules()->closingWindowSeconds;

        if ($remaining === null || $window <= 0) {
            return false;
        }

        return $remaining <= $window;
    }

    /**
     * How many seconds a bid landing now would add to the clock.
     *
     * Zero when nothing would be added, which covers every reason at once:
     * extensions switched off, the bid is not in the closing window, the
     * count limit is used up, or the total budget is exhausted.
     *
     * Both ruleset limits apply. The budget is trimmed rather than exceeded,
     * so an auction can never run longer than
     * {@see AuctionRules::maximumPossibleDurationSeconds()} promised.
     */
    public function extensionFor(Auction $auction, ?Carbon $now = null): int
    {
        $rules = $auction->rules();

        if (! $rules->extensionsEnabled()) {
            return 0;
        }

        if (! $this->isInClosingWindow($auction, $now)) {
            return 0;
        }

        if ($auction->extensions_applied >= $rules->maxExtensions) {
            return 0;
        }

        $budgetLeft = $rules->maxExtensionTotalSeconds - $auction->extension_seconds_applied;

        if ($budgetLeft <= 0) {
            return 0;
        }

        return min($rules->extensionSeconds, $budgetLeft);
    }

    /**
     * The latest this auction could possibly end, given its frozen rules.
     *
     * Not a guess about bidder behaviour -- the ceiling. Useful for operations
     * planning, and for reassuring a bidder that an auction cannot be extended
     * indefinitely.
     */
    public function latestPossibleEnd(Auction $auction): ?Carbon
    {
        if ($auction->ends_at === null) {
            return null;
        }

        $rules = $auction->rules();

        if (! $rules->extensionsEnabled()) {
            return $auction->ends_at->copy();
        }

        $remainingByCount = ($rules->maxExtensions - $auction->extensions_applied) * $rules->extensionSeconds;
        $remainingByBudget = $rules->maxExtensionTotalSeconds - $auction->extension_seconds_applied;

        return $auction->ends_at->copy()->addSeconds(max(0, min($remainingByCount, $remainingByBudget)));
    }
}

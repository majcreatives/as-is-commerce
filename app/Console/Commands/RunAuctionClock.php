<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\ForfeitAuction;
use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Advance every auction whose clock has moved past it.
 *
 * WHY THIS EXISTS. An auction runs for days. Nothing about it may depend on a
 * page being open, a browser tab surviving, a JavaScript timer firing or a PHP
 * process staying alive -- so something outside all of that has to notice that
 * a start time or an end time has passed. This sweep is that something.
 *
 * A user closing their laptop changes nothing. A server restart changes
 * nothing: the next run reads the same timestamps and reaches the same
 * conclusions, because the decisions live in the database rather than in
 * anything's memory. Missing a run delays a closure; it never changes its
 * outcome, since the winner is resolved from the bid records at the moment of
 * closing and the bids do not change while the sweep is late.
 *
 * Everything here is idempotent. Each action re-reads its auction under a row
 * lock and returns unchanged if another run, or a Buy Now, got there first.
 * Two overlapping sweeps therefore produce one closure, not two.
 *
 * One auction failing must not stop the others: a product-level problem on one
 * auction should not leave every other auction on the platform open past its
 * end time. Failures are logged individually and the sweep continues.
 */
class RunAuctionClock extends Command
{
    protected $signature = 'auctions:tick {--limit=200 : Most auctions to advance in one pass}';

    protected $description = 'Start, mark closing, close and forfeit auctions whose time has come';

    public function handle(
        AuctionLifecycle $lifecycle,
        AuctionClock $clock,
        CloseAuction $close,
        ForfeitAuction $forfeit,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $now = Carbon::now();

        $started = $this->startDueAuctions($lifecycle, $limit, $now);
        $closing = $this->markClosingAuctions($lifecycle, $clock, $limit, $now);
        $closed = $this->closeExpiredAuctions($close, $limit, $now);
        $forfeited = $this->forfeitLapsedSettlements($forfeit, $limit, $now);

        $this->info(
            "Started {$started}, entered closing {$closing}, closed {$closed}, forfeited {$forfeited}."
        );

        Cache::put('sweeps:auctions_tick:last_run', Carbon::now()->toIso8601String());

        return self::SUCCESS;
    }

    /**
     * Scheduled auctions whose start time has arrived.
     */
    private function startDueAuctions(AuctionLifecycle $lifecycle, int $limit, Carbon $now): int
    {
        $count = 0;

        foreach (Auction::query()->dueToStart()->limit($limit)->get() as $auction) {
            $this->attempt($auction, 'start', function () use ($lifecycle, $auction, $now, &$count): void {
                $lifecycle->start($auction, null, $now);
                $count++;
            });
        }

        return $count;
    }

    /**
     * Live auctions that have entered their closing window.
     *
     * Purely informational -- bids and Buy Now behave identically in Closing --
     * so a missed run here costs nothing but a label.
     */
    private function markClosingAuctions(
        AuctionLifecycle $lifecycle,
        AuctionClock $clock,
        int $limit,
        Carbon $now,
    ): int {
        $count = 0;

        $candidates = Auction::query()
            ->where('status', AuctionStatus::Live)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', $now)
            ->limit($limit)
            ->get();

        foreach ($candidates as $auction) {
            if (! $clock->isInClosingWindow($auction, $now)) {
                continue;
            }

            $this->attempt($auction, 'enter-closing', function () use ($lifecycle, $auction, $now, &$count): void {
                $lifecycle->enterClosing($auction, $now);
                $count++;
            });
        }

        return $count;
    }

    /**
     * Open auctions whose clock has run out.
     *
     * The one that decides winners. Never forced: an auction is closed because
     * its own `ends_at` has passed, which the action re-checks under the lock
     * in case a late bid extended it since this query ran.
     */
    private function closeExpiredAuctions(CloseAuction $close, int $limit, Carbon $now): int
    {
        $count = 0;

        foreach (Auction::query()->dueToClose()->limit($limit)->get() as $auction) {
            $this->attempt($auction, 'close', function () use ($close, $auction, $now, &$count): void {
                $closed = $close->handle($auction, false, $now);

                if (! $closed->status->acceptsBids()) {
                    $count++;
                }
            });
        }

        return $count;
    }

    /**
     * Winners who did not settle within the deadline their auction allowed.
     *
     * The deadline comes from each auction's own frozen snapshot, so a ruleset
     * changed since cannot shorten or extend a deadline someone was already
     * given. This applies the existing forfeit policy and invents nothing
     * beyond it -- in particular, no credits are returned, and the winner's
     * outstanding checkout is closed along with the auction.
     */
    private function forfeitLapsedSettlements(ForfeitAuction $forfeit, int $limit, Carbon $now): int
    {
        $count = 0;

        $lapsed = Auction::query()
            ->where('status', AuctionStatus::PendingSettlement)
            ->whereNotNull('settlement_due_at')
            ->where('settlement_due_at', '<=', $now)
            ->limit($limit)
            ->get();

        foreach ($lapsed as $auction) {
            $this->attempt($auction, 'forfeit', function () use ($forfeit, $auction, &$count): void {
                // Through the action, not the lifecycle: forfeiting also has
                // to close the winner's outstanding checkout, or they could
                // pay for a unit that just went back on sale.
                $forfeit->handle($auction);
                $count++;
            });
        }

        return $count;
    }

    /**
     * Run one auction's step, and keep going if it fails.
     */
    private function attempt(Auction $auction, string $step, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            // Logged rather than rethrown. A single auction with, say, an
            // inventory problem must not leave every other auction on the
            // platform running past its end time.
            Log::error('Auction clock step failed', [
                'operation' => 'auction.tick.'.$step,
                'auction_id' => $auction->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->warn("Auction #{$auction->id} failed to {$step}: {$e->getMessage()}");
        }
    }
}

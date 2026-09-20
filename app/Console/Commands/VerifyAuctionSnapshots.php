<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Auction\Exceptions\InvalidAuctionRules;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Auction\ValueObjects\AuctionSnapshot;
use App\Models\Auction;
use Illuminate\Console\Command;

/**
 * Checks that every auction's frozen rules can still be read and still agree
 * with the records around them. Reports; repairs nothing.
 *
 * WHY THIS EXISTS. The engine refuses a snapshot it does not understand rather
 * than reinterpret it, which is right at the moment somebody bids and wrong as
 * a way to find out. A snapshot that fails to load surfaces as an error on a
 * live page, in front of a customer. This finds them first: run it after any
 * change to the snapshot shape, and after any migration that rewrites one.
 *
 * WHAT IT CHECKS, PER AUCTION
 *
 *   readable     The snapshot loads under the current engine. This is the check
 *                that catches a version the engine refuses, an unknown bid
 *                model, or a bid model and winner rule that disagree.
 *
 *   versioned    The outer snapshot version stored on the row matches the one
 *                inside the JSON, and both match the current shape.
 *
 *   projection   The cached highest-bid columns still match what the bid
 *                records say. That is the resolver's own check, run here so one
 *                command answers "is this auction in a state the engine trusts".
 *
 * IT NEVER REPAIRS. A snapshot that disagrees with something is a snapshot
 * somebody has to look at: rewriting it automatically would be a guess about
 * which of two disagreeing records is right, made by the code whose bug may
 * have caused the disagreement. Exits non-zero when anything is found, so a
 * deploy step or a scheduler can act on it.
 *
 * READ-ONLY, AND BOUNDED IN MEMORY. Auctions are read in chunks, so it is safe
 * to run against a large table.
 */
class VerifyAuctionSnapshots extends Command
{
    protected $signature = 'auctions:verify-snapshots';

    protected $description = 'Check every auction snapshot still loads and agrees with its records (reports, never repairs)';

    public function handle(HighestBidResolver $bids): int
    {
        $checked = 0;
        $byModel = [];
        $problems = [];

        Auction::query()->orderBy('id')->chunkById(100, function ($auctions) use ($bids, &$checked, &$byModel, &$problems): void {
            foreach ($auctions as $auction) {
                $checked++;

                try {
                    $snapshot = $auction->snapshot();
                } catch (InvalidAuctionRules $e) {
                    $problems[] = [$auction->id, $auction->status->value, 'unreadable snapshot', $e->getMessage()];

                    continue;
                }

                $model = $snapshot->rules->bidModel->value;
                $byModel[$model] = ($byModel[$model] ?? 0) + 1;

                if ($auction->snapshot_version !== AuctionSnapshot::SNAPSHOT_VERSION
                    || (int) ($auction->rules_snapshot['snapshot_version'] ?? 0) !== AuctionSnapshot::SNAPSHOT_VERSION) {
                    $problems[] = [
                        $auction->id,
                        $auction->status->value,
                        'version mismatch',
                        'row says '.$auction->snapshot_version.', JSON says '
                            .($auction->rules_snapshot['snapshot_version'] ?? 'nothing')
                            .', engine expects '.AuctionSnapshot::SNAPSHOT_VERSION,
                    ];
                }

                $projection = $bids->verify($auction);

                if (! $projection['matches']) {
                    $problems[] = [
                        $auction->id,
                        $auction->status->value,
                        'projection disagrees with the bids',
                        'projected '.($projection['projected_highest_bid_id'] ?? 'none').'/'.$projection['projected_bid_count']
                            .' bids, records say '.($projection['actual_highest_bid_id'] ?? 'none').'/'.$projection['actual_bid_count'],
                    ];
                }
            }
        });

        $this->line("Checked {$checked} auction(s).");

        foreach ($byModel as $model => $count) {
            $this->line("  {$model}: {$count}");
        }

        if ($problems === []) {
            $this->info('Every snapshot loads and agrees with its records.');

            return self::SUCCESS;
        }

        $this->error(count($problems).' problem(s) found. Nothing has been changed.');
        $this->table(['Auction', 'Status', 'Problem', 'Detail'], $problems);

        return self::FAILURE;
    }
}

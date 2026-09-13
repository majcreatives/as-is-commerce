<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Referrals\Services\ReferralReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Reports referrals whose records do not add up.
 *
 * IT ISSUES NO CREDITS, EVER. That is the whole design constraint. A command
 * that quietly granted rewards it thought were missing would be the most
 * dangerous thing in this stage: one bug in the qualifying rule and every run
 * would mint credits, into an immutable ledger, with nothing to take them back.
 *
 * So this reports and exits. A qualified referral that was never rewarded is
 * surfaced, and somebody decides whether the cap, the switch or a failure was
 * the reason -- and issues it through the ordinary reward path if it should be.
 *
 * Not scheduled. Referral anomalies are not time-sensitive the way an unsettled
 * refund is, and a command whose only output is a report is one somebody runs
 * when they want the report.
 */
class ReconcileReferrals extends Command
{
    protected $signature = 'referrals:reconcile {--limit=200 : Most referrals to inspect}';

    protected $description = 'Report referrals whose records disagree. Repairs nothing.';

    public function handle(ReferralReconciler $reconciler): int
    {
        $summary = $reconciler->summary();

        $this->table(
            ['Attributed', 'Qualified', 'Rewarded', 'Not eligible', 'Credits issued'],
            [[
                $summary['attributed'],
                $summary['qualified'],
                $summary['rewarded'],
                $summary['invalidated'],
                number_format($summary['credits_issued']),
            ]],
        );

        Cache::put('sweeps:reconcile_referrals:last_run', now()->toIso8601String());

        $anomalies = $reconciler->report(max(1, (int) $this->option('limit')));

        if ($anomalies === []) {
            $this->info('No referral anomalies.');

            return self::SUCCESS;
        }

        $this->warn(count($anomalies).' referral '.(count($anomalies) === 1 ? 'anomaly' : 'anomalies').':');

        $this->table(
            ['Type', 'Referral', 'Detail'],
            array_map(fn (array $a): array => [
                $a['type'],
                $a['referral_id'] ?? '-',
                $a['detail'],
            ], $anomalies),
        );

        $this->line('');
        $this->line('Nothing has been changed. Reconciliation reports; a person decides.');

        // Non-zero so a monitor notices without reading logs.
        return self::FAILURE;
    }
}

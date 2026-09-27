<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Credit\Services\CreditLedgerReconciler;
use Illuminate\Console\Command;

/**
 * Reports credit wallets whose materialized balance disagrees with its ledger.
 *
 * IT MOVES NO CREDITS, EVER, and that is the entire design. The ledger is
 * authoritative and the balance is a projection of it, so a command that
 * "corrected" the projection would be choosing which of two disagreeing
 * records to believe -- quietly, on every run, forever. It would also destroy
 * the evidence needed to work out what caused the drift, which is the only
 * thing worth having when a customer's credits and their bid history disagree
 * about what they own.
 *
 * So it reports and exits non-zero. A person reads the ledger, works out which
 * side is wrong, and posts a compensating entry. Nothing is edited and nothing
 * is deleted, because an immutable ledger that can be quietly rewritten is not
 * one.
 *
 * This exists as a command as well as a line in the exception centre on
 * purpose. The screen is where an operator already looks; the command is how
 * the same question gets asked on a schedule or during an incident, without
 * somebody having to be logged in to ask it.
 */
class ReconcileCredits extends Command
{
    protected $signature = 'credits:reconcile {--limit= : Most wallets to inspect}';

    protected $description = 'Report credit wallets whose balance disagrees with their ledger. Repairs nothing.';

    public function handle(CreditLedgerReconciler $reconciler): int
    {
        $limit = (int) $this->option('limit');
        $limit = $limit > 0 ? $limit : CreditLedgerReconciler::MAX_CHECKED;

        $this->line('Reading at most '.$limit.' wallet(s), in id order.');
        $this->line('');

        $problems = $reconciler->reconcileAll($limit);

        // ->isEmpty(), not === []. The reconciler hands back a Collection, and
        // an empty Collection is never identical to an empty array, so the
        // array comparison is always false and this command would report a
        // clean platform as broken. `referrals:reconcile` gets away with `=== []`
        // only because its reconciler happens to return a plain array.
        if ($problems->isEmpty()) {
            $this->info('Every credit wallet checked agrees with its ledger.');

            return self::SUCCESS;
        }

        $this->warn($problems->count().' credit wallet(s) disagree with their ledger:');
        $this->line('');

        foreach ($problems as $report) {
            $this->line('  Credit Wallet #'.$report->walletId);
            $this->line('    stored balance:   '.$report->storedBalance);
            $this->line('    ledger balance:   '.$report->ledgerBalance);
            $this->line('    lots remaining:   '.$report->lotsRemaining);

            foreach ($report->problems as $problem) {
                $this->line('    - '.$problem);
            }

            $this->line('');
        }

        $this->line('Nothing has been changed. The ledger is authoritative; reconciliation reports, a person decides.');
        $this->line('Open the customer on the Wallets screen to see which side is wrong.');

        // Non-zero so a scheduled run or a monitor notices without reading logs.
        return self::FAILURE;
    }
}

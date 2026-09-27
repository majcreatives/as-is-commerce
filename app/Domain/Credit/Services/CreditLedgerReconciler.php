<?php

declare(strict_types=1);

namespace App\Domain\Credit\Services;

use App\Domain\Credit\ValueObjects\ReconciliationReport;
use App\Domain\StoreWallet\Services\StoreWalletReconciler;
use App\Models\CreditLot;
use App\Models\CreditLotConsumption;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Checks that a wallet's several representations of the same truth agree.
 *
 * The ledger is authoritative, but three things are derived from it -- the
 * materialized balance, the lots' remaining amounts, and each row's
 * balance_after. A bug, a bad migration or a hand-run UPDATE could put any of
 * them out of step, and the whole point of double-entry style bookkeeping is
 * that such a drift is detectable rather than invisible.
 *
 * This reports discrepancies. It deliberately does not repair them: silently
 * "fixing" a financial inconsistency destroys the evidence needed to work out
 * what caused it, and would let a real bug keep producing wrong numbers while
 * looking healthy. Repair is a human decision, made with a compensating
 * entry.
 */
class CreditLedgerReconciler
{
    /**
     * Deliberately small, and matching the store wallet reconciler's ceiling.
     *
     * This is a triage sweep, not a full audit: it answers "is anything
     * obviously broken right now", and an operations screen needs that answer
     * on the worst day of the business, which is exactly the day a full pass
     * over every customer's lots would be least affordable.
     */
    public const MAX_CHECKED = 200;

    /**
     * Wallets whose materialized balance does not match the sum of their
     * ledger entries.
     *
     * The single-query version, for the exception centre: fast, bounded, and
     * enough to catch the headline integrity failure. The deeper per-wallet
     * checks in {@see reconcile()} -- lot conservation, balance_after
     * continuity -- walk the whole history and so belong on the dedicated admin
     * screen for one customer, not on a page that lists everybody.
     *
     * This mirrors {@see StoreWalletReconciler::projectionMismatches()}
     * deliberately. The credit and cash sides are separate systems, but the
     * way each answers "is this projection lying" should not differ, because
     * the argument for being able to see it is identical.
     *
     * @return Collection<int, CreditWallet>
     */
    public function projectionMismatches(int $limit = self::MAX_CHECKED): Collection
    {
        return CreditWallet::query()
            ->leftJoinSub(
                CreditTransaction::query()
                    ->select('credit_wallet_id')
                    ->selectRaw('SUM(amount) as ledger_sum')
                    ->groupBy('credit_wallet_id'),
                'ledger', 'ledger.credit_wallet_id', '=', 'credit_wallets.id',
            )
            ->where(fn (Builder $q): Builder => $q
                ->whereRaw('credit_wallets.balance <> COALESCE(ledger.ledger_sum, 0)')
                // A negative credit balance is a separate failure from a
                // mismatched one: the sum can agree with itself while being
                // wrong, because a deduction was allowed that should not have
                // been. The column is unsigned, so a negative total can only
                // come from the ledger side.
                ->orWhereRaw('COALESCE(ledger.ledger_sum, 0) < 0'))
            ->limit($limit)
            ->get();
    }

    public function reconcile(CreditWallet $wallet): ReconciliationReport
    {
        $problems = [];

        $ledgerSum = (int) CreditTransaction::where('credit_wallet_id', $wallet->id)->sum('amount');
        $lotsRemaining = (int) CreditLot::where('credit_wallet_id', $wallet->id)->sum('remaining_amount');
        $storedBalance = $wallet->balance;

        // 1. The balance column must equal the sum of every ledger movement.
        if ($storedBalance !== $ledgerSum) {
            $problems[] = sprintf(
                'Wallet balance (%d) does not equal the sum of ledger amounts (%d); difference %+d.',
                $storedBalance,
                $ledgerSum,
                $storedBalance - $ledgerSum,
            );
        }

        // 2. Credits still sitting in lots must equal the balance. If these
        //    disagree, a debit changed the balance without drawing from a lot,
        //    or vice versa.
        if ($lotsRemaining !== $storedBalance) {
            $problems[] = sprintf(
                'Remaining credit in lots (%d) does not equal the wallet balance (%d); difference %+d.',
                $lotsRemaining,
                $storedBalance,
                $lotsRemaining - $storedBalance,
            );
        }

        $problems = array_merge(
            $problems,
            $this->checkLotIntegrity($wallet),
            $this->checkRunningBalance($wallet),
        );

        return new ReconciliationReport(
            walletId: $wallet->id,
            storedBalance: $storedBalance,
            ledgerBalance: $ledgerSum,
            lotsRemaining: $lotsRemaining,
            problems: $problems,
        );
    }

    /**
     * Every lot's consumption records must account for exactly the difference
     * between what it started with and what it has left.
     *
     * @return list<string>
     */
    private function checkLotIntegrity(CreditWallet $wallet): array
    {
        $problems = [];

        /** @var Collection<int, CreditLot> $lots */
        $lots = CreditLot::where('credit_wallet_id', $wallet->id)->orderBy('id')->get();

        foreach ($lots as $lot) {
            if ($lot->remaining_amount > $lot->original_amount) {
                $problems[] = sprintf(
                    'Lot #%d has more remaining (%d) than it originally held (%d).',
                    $lot->id,
                    $lot->remaining_amount,
                    $lot->original_amount,
                );
            }

            if ($lot->remaining_amount < 0) {
                $problems[] = sprintf('Lot #%d has a negative remaining amount (%d).', $lot->id, $lot->remaining_amount);
            }

            $consumed = (int) CreditLotConsumption::where('credit_lot_id', $lot->id)->sum('amount');
            $expected = $lot->original_amount - $lot->remaining_amount;

            if ($consumed !== $expected) {
                $problems[] = sprintf(
                    'Lot #%d records %d consumed but its remaining amount implies %d.',
                    $lot->id,
                    $consumed,
                    $expected,
                );
            }
        }

        return $problems;
    }

    /**
     * Replaying the ledger in order must reproduce every stored balance_after.
     *
     * This catches a transaction written with the wrong running balance, which
     * neither of the sum-based checks would notice if the errors cancelled out.
     *
     * @return list<string>
     */
    private function checkRunningBalance(CreditWallet $wallet): array
    {
        $problems = [];
        $running = 0;

        /** @var Collection<int, CreditTransaction> $transactions */
        $transactions = CreditTransaction::where('credit_wallet_id', $wallet->id)
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            $running += $transaction->amount;

            if ($running < 0) {
                $problems[] = sprintf(
                    'Replaying the ledger drives the balance negative (%d) at transaction #%d.',
                    $running,
                    $transaction->id,
                );
            }

            if ($transaction->balance_after !== $running) {
                $problems[] = sprintf(
                    'Transaction #%d records balance_after %d but the replayed balance is %d.',
                    $transaction->id,
                    $transaction->balance_after,
                    $running,
                );
            }
        }

        return $problems;
    }

    /**
     * Reconcile every wallet, returning only those with problems.
     *
     * Bounded, and that is not a detail. This is one query per wallet plus
     * more per lot, so `get()` without a ceiling is a table scan that degrades
     * exactly as the customer base grows and the site is busiest. It is also
     * the kind of method that looks harmless until the first person wires it
     * into a page that an operator opens on their worst day, so the ceiling
     * lives here rather than at the call site where it can be forgotten.
     *
     * The order is by id so a truncated run inspects the same wallets every
     * time instead of drifting, which is what makes a bounded sweep
     * reproducible rather than merely finite.
     *
     * @return Collection<int, ReconciliationReport>
     */
    public function reconcileAll(int $limit = self::MAX_CHECKED): Collection
    {
        return CreditWallet::query()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (CreditWallet $w): ReconciliationReport => $this->reconcile($w))
            ->reject(fn (ReconciliationReport $r): bool => $r->isHealthy())
            ->values();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\Services;

use App\Enums\StoreWalletTransactionType;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Checks that a Store Wallet's ledger, running balance and transaction
 * integrity all agree.
 *
 * The ledger is authoritative; the balance column is a cache. This reports
 * discrepancies between them. It deliberately does not repair them:
 * silently "fixing" a financial inconsistency destroys the evidence needed
 * to work out what caused it.
 *
 * EVERY CHECK IS BOUNDED. An operations screen is most needed on the worst
 * day, which is exactly the day an unbounded query would take the site
 * down with it.
 *
 * REPORT ONLY. A person decides what to do about each problem. There is no
 * "dismiss" or "repair" method here.
 */
class StoreWalletReconciler
{
    public const MAX_CHECKED = 200;

    /**
     * Wallets whose materialized balance does not match the sum of their
     * ledger entries.
     *
     * This is the single-query version for the exception centre: fast,
     * bounded, and enough to detect the headline integrity failure.  The
     * deeper per-wallet checks live in {@see reconcile()} and are run on
     * the dedicated admin screen only.
     *
     * @return Collection<int, StoreWallet>
     */
    public function projectionMismatches(int $limit = self::MAX_CHECKED): Collection
    {
        return StoreWallet::query()
            ->leftJoinSub(
                StoreWalletTransaction::query()
                    ->select('store_wallet_id')
                    ->selectRaw('SUM(amount_minor) as ledger_sum')
                    ->groupBy('store_wallet_id'),
                'ledger',
                'ledger.store_wallet_id',
                '=',
                'store_wallets.id',
            )
            ->where(fn (Builder $q): Builder => $q
                ->whereRaw('store_wallets.balance_minor <> COALESCE(ledger.ledger_sum, 0)')
                ->orWhere('store_wallets.balance_minor', '<', 0))
            ->limit($limit)
            ->get();
    }

    /**
     * Reconcile one wallet's ledger against its materialized balance.
     */
    public function reconcile(StoreWallet $wallet): StoreWalletReport
    {
        $problems = [];
        $projectedBalance = (int) StoreWalletTransaction::where('store_wallet_id', $wallet->id)->sum('amount_minor');
        $storedBalance = $wallet->balance_minor;

        if ($storedBalance !== $projectedBalance) {
            $problems[] = sprintf(
                'Wallet balance (%d) does not equal the sum of ledger amounts (%d); difference %+d.',
                $storedBalance,
                $projectedBalance,
                $storedBalance - $projectedBalance,
            );
        }

        $problems = array_merge(
            $problems,
            $this->checkRunningBalance($wallet),
            $this->checkTransactionIntegrity($wallet),
        );

        return new StoreWalletReport(
            walletId: $wallet->id,
            userId: $wallet->user_id,
            storedBalance: $storedBalance,
            ledgerBalance: $projectedBalance,
            problems: $problems,
        );
    }

    /**
     * Reconcile every wallet, returning only those with problems.
     *
     * @return Collection<int, StoreWalletReport>
     */
    public function reconcileAll(): Collection
    {
        return StoreWallet::query()
            ->orderBy('id')
            ->limit(self::MAX_CHECKED)
            ->get()
            ->map(fn (StoreWallet $wallet): StoreWalletReport => $this->reconcile($wallet))
            ->reject(fn (StoreWalletReport $report): bool => $report->isHealthy())
            ->values();
    }

    /**
     * Replaying the ledger in order must reproduce every stored balance_after.
     *
     * @return list<string>
     */
    private function checkRunningBalance(StoreWallet $wallet): array
    {
        $problems = [];
        $running = 0;

        /** @var Collection<int, StoreWalletTransaction> $transactions */
        $transactions = StoreWalletTransaction::where('store_wallet_id', $wallet->id)
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            $running += $transaction->amount_minor;

            if ($running < 0) {
                $problems[] = sprintf(
                    'Replaying the ledger drives the balance negative (%d) at transaction #%d.',
                    $running,
                    $transaction->id,
                );
            }

            if ($transaction->balance_after_minor !== $running) {
                $problems[] = sprintf(
                    'Transaction #%d records balance_after %d but the replayed balance is %d.',
                    $transaction->id,
                    $transaction->balance_after_minor,
                    $running,
                );
            }
        }

        return $problems;
    }

    /**
     * Validate each transaction's sign, reference, and credit-source
     * integrity.
     *
     * @return list<string>
     */
    private function checkTransactionIntegrity(StoreWallet $wallet): array
    {
        $problems = [];

        /** @var Collection<int, StoreWalletTransaction> $transactions */
        $transactions = StoreWalletTransaction::where('store_wallet_id', $wallet->id)
            ->with('creditSources')
            ->orderBy('id')
            ->get();

        foreach ($transactions as $transaction) {
            if (! $transaction->type->allowsAmount($transaction->amount_minor)) {
                $problems[] = sprintf(
                    'Transaction #%d has a %s amount (%d) that does not match its type (%s).',
                    $transaction->id,
                    $transaction->amount_minor >= 0 ? 'positive' : 'negative',
                    $transaction->amount_minor,
                    $transaction->type->value,
                );
            }

            if ($transaction->type->carriesCreditSources()) {
                if ($transaction->reference_type === null || $transaction->reference_id === null) {
                    $problems[] = sprintf(
                        'Issuance transaction #%d is missing a reference (expected an Auction).',
                        $transaction->id,
                    );
                }

                $sourcesTotal = $transaction->creditSources->sum('amount_minor');

                if ($sourcesTotal !== $transaction->amount_minor) {
                    $problems[] = sprintf(
                        'Issuance transaction #%d has credit sources totalling %d but the transaction amount is %d.',
                        $transaction->id,
                        $sourcesTotal,
                        $transaction->amount_minor,
                    );
                }
            }

            if ($transaction->type === StoreWalletTransactionType::OrderApplied
                || $transaction->type === StoreWalletTransactionType::OrderReleased) {
                if ($transaction->reference_type === null || $transaction->reference_id === null) {
                    $problems[] = sprintf(
                        'Transaction #%d (%s) is missing a reference (expected an Order).',
                        $transaction->id,
                        $transaction->type->value,
                    );
                }
            }
        }

        return $problems;
    }
}

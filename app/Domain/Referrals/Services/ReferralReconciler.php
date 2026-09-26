<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Services;

use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Enums\CreditTransactionType;
use App\Enums\ReferralStatus;
use App\Models\Referral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Looks for referrals whose records do not add up.
 *
 * IT DETECTS. IT DOES NOT REPAIR, and it certainly does not issue credits. The
 * same principle as the credit ledger's own reconciliation and the refund
 * reconciler: an automatic repair is a guess made by the code whose bug may
 * have caused the disagreement, and here the guess would create money.
 *
 * A command that quietly granted "missing" rewards would be the single most
 * dangerous thing in this stage -- one bug in the qualifying rule and it would
 * mint credits on every run. So the report says what looks wrong, and a person
 * decides.
 *
 * WHAT IT LOOKS FOR:
 *
 *   Unrewarded qualification  Somebody bought something and the referrer was
 *                             never paid. Usually the cap or a switched-off
 *                             programme, sometimes a failed ledger write.
 *   Reward without evidence   Marked rewarded with no ledger row, no amount,
 *                             or no qualifying order. Should be impossible --
 *                             CHECK constraints refuse it -- which is exactly
 *                             why it is worth checking.
 *   Ledger disagreement       The snapshot on the referral and the amount on
 *                             the credit transaction differ, or the credits
 *                             went to the wrong wallet.
 *   Wrong transaction type    A referral pointing at a ledger row that is not
 *                             a referral credit.
 *   Duplicate rewards         Two referrals claiming one credit transaction.
 *                             A unique index refuses it; checked anyway.
 *   Self-referral             Refused by a CHECK constraint, and checked here
 *                             because the cost of one slipping through is a
 *                             customer paying themselves.
 *   Referral ring             A introduces B, B introduces A. The per-referrer
 *                             cap does not apply to a group, and no single row
 *                             looks wrong; only the whole graph does.
 */
class ReferralReconciler
{
    /**
     * How far up an introduction chain to walk before giving up.
     *
     * A backstop behind the seen-set, not the primary guard: the seen-set is
     * what actually terminates the walk, and this only bounds the pathological
     * case where a data problem produced a chain far longer than any real one.
     * Thirty is far more introductions deep than a genuine programme produces.
     */
    private const MAX_CHAIN_DEPTH = 30;

    /**
     * Everything that does not add up.
     *
     * @return list<array{type: string, referral_id: int|null, detail: string}>
     */
    public function report(int $limit = 200): array
    {
        $anomalies = [
            ...$this->unrewardedQualifications($limit),
            ...$this->rewardsWithoutEvidence($limit),
            ...$this->ledgerDisagreements($limit),
            ...$this->duplicateRewards(),
            ...$this->selfReferrals(),
            ...$this->cycles($limit),
        ];

        foreach ($anomalies as $anomaly) {
            Log::warning('Referral reconciliation anomaly', [
                'operation' => 'referral.reconciliation_anomaly',
                'type' => $anomaly['type'],
                'referral_id' => $anomaly['referral_id'],
                'detail' => $anomaly['detail'],
            ]);
        }

        return $anomalies;
    }

    /**
     * A summary an administrator can read at a glance.
     *
     * Counted from the referral records, never from a wallet balance: credits
     * move for a dozen reasons and a balance says nothing about how many
     * people somebody introduced.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $byStatus = Referral::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (ReferralStatus::cases() as $status) {
            $counts[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        $counts['total'] = Referral::query()->count();

        // Summed from the snapshots, which are what was actually granted.
        $counts['credits_issued'] = (int) Referral::query()
            ->where('status', ReferralStatus::Rewarded)
            ->sum('reward_credits');

        return $counts;
    }

    /**
     * @return list<array{type: string, referral_id: int|null, detail: string}>
     */
    private function unrewardedQualifications(int $limit): array
    {
        return Referral::query()
            ->awaitingReward()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Referral $r): array => [
                'type' => 'qualified_not_rewarded',
                'referral_id' => $r->id,
                'detail' => 'Qualified on '
                    .($r->qualified_at?->toDateTimeString() ?? 'an unknown date')
                    .' and no reward has been issued.',
            ])
            ->all();
    }

    /**
     * @return list<array{type: string, referral_id: int|null, detail: string}>
     */
    private function rewardsWithoutEvidence(int $limit): array
    {
        return Referral::query()
            ->rewarded()
            ->where(fn ($q) => $q->whereNull('credit_transaction_id')
                ->orWhereNull('reward_credits')
                ->orWhereNull('qualifying_order_id'))
            ->limit($limit)
            ->get()
            ->map(fn (Referral $r): array => [
                'type' => 'reward_without_evidence',
                'referral_id' => $r->id,
                'detail' => 'Marked rewarded without an amount, a ledger row or a qualifying order.',
            ])
            ->all();
    }

    /**
     * @return list<array{type: string, referral_id: int|null, detail: string}>
     */
    private function ledgerDisagreements(int $limit): array
    {
        $anomalies = [];

        $rewarded = Referral::query()
            ->rewarded()
            ->whereNotNull('credit_transaction_id')
            ->with('creditTransaction.wallet')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($rewarded as $referral) {
            $transaction = $referral->creditTransaction;

            if ($transaction === null) {
                $anomalies[] = [
                    'type' => 'missing_ledger_row',
                    'referral_id' => $referral->id,
                    'detail' => 'Points at a credit transaction that cannot be read.',
                ];

                continue;
            }

            if ($transaction->amount !== $referral->reward_credits) {
                $anomalies[] = [
                    'type' => 'amount_disagreement',
                    'referral_id' => $referral->id,
                    'detail' => 'Snapshot says '.CreditAmount::fromSubcredits((int) $referral->reward_credits)
                        .'; the ledger says '.CreditAmount::fromSubcredits((int) $transaction->amount).'.',
                ];
            }

            if ($transaction->type !== CreditTransactionType::ReferralCredit) {
                $anomalies[] = [
                    'type' => 'wrong_transaction_type',
                    'referral_id' => $referral->id,
                    'detail' => "Points at a [{$transaction->type->value}] transaction rather than a referral credit.",
                ];
            }

            if ($transaction->wallet !== null
                && $transaction->wallet->user_id !== $referral->referrer_user_id) {
                $anomalies[] = [
                    'type' => 'wrong_recipient',
                    'referral_id' => $referral->id,
                    'detail' => 'The credits reached a wallet that does not belong to the referrer.',
                ];
            }
        }

        return $anomalies;
    }

    /**
     * @return list<array{type: string, referral_id: int|null, detail: string}>
     */
    private function duplicateRewards(): array
    {
        return DB::table('referrals')
            ->select('credit_transaction_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('credit_transaction_id')
            ->groupBy('credit_transaction_id')
            ->having('total', '>', 1)
            ->get()
            ->map(fn (object $row): array => [
                'type' => 'duplicate_reward',
                'referral_id' => null,
                'detail' => "Credit transaction {$row->credit_transaction_id} is claimed by {$row->total} referrals.",
            ])
            ->all();
    }

    /**
     * Referral rings: A introduces B, B introduces A.
     *
     * THE ONE ABUSE THE PER-REFERRER CAP DOES NOT TOUCH. A cap counts one
     * referrer's rewards, and in a ring every account is a different referrer,
     * so twenty accounts in a circle each earn the full reward while paying
     * only the cheapest qualifying purchase. The cap that stops one customer
     * farming twenty referrals does nothing here, and neither does the
     * self-referral CHECK: that one looks at a single row, and no row in a ring
     * is self-referential. Only the shape of the whole graph gives it away.
     *
     * WHY THE LOOP IS BOUNDED RATHER THAN ASSUMED ABSENT. Following who
     * introduced whom terminates on an honest graph, because each step is a
     * distinct person and there are finitely many. It does not terminate on a
     * ring, which is the very thing being looked for -- so a seen-set carries
     * the walk, and a hop limit stands behind it. A detector that hangs on
     * finding the problem is worse than no detector, because it takes the
     * reconciliation command down with it.
     *
     * REPORTED, NOT REFUSED. Not a cap, and not this method's decision to make:
     * a ring is a shape, and whether one is a family sharing a phone or a
     * deliberate fraud is a question for a person with context this report does
     * not have. It is also not refusable in general -- see the class comment on
     * why nothing here can move credits.
     *
     * @return list<array{type: string, referral_id: int|null, detail: string}>
     */
    private function cycles(int $limit): array
    {
        // Every introduction on the platform, as plain edges. Read once rather
        // than per-referral: this is a graph walk, and a query inside the loop
        // would make it quadratic on a table that is meant to stay small.
        $edges = DB::table('referrals')
            ->select('id', 'referrer_user_id', 'referred_user_id')
            ->orderBy('id')
            ->get();

        if ($edges->isEmpty()) {
            return [];
        }

        // who introduced whom, for the walk up the chain
        $introduced = [];

        foreach ($edges as $edge) {
            $introduced[(int) $edge->referred_user_id] = (int) $edge->referrer_user_id;
        }

        $anomalies = [];
        $reported = [];

        foreach ($edges as $edge) {
            if (count($anomalies) >= $limit) {
                break;
            }

            $referred = (int) $edge->referred_user_id;
            $referrer = (int) $edge->referrer_user_id;

            // A ring is a property of the whole cycle, not of one edge, so it is
            // reported once per ring rather than once per row. Keyed on the
            // lowest member id, which every member of the same ring computes
            // identically.
            $cycle = $this->ringContaining($referrer, $referred, $introduced);

            if ($cycle === null) {
                continue;
            }

            $key = 'ring:'.min($cycle);

            if (isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;

            $anomalies[] = [
                'type' => 'referral_ring',
                'referral_id' => (int) $edge->id,
                'detail' => 'These customers each introduced the next around a closed loop: users '
                    .implode(' → ', $cycle).' → '.min($cycle).'. Every one of them counts as a '
                    .'separate referrer, so a per-referrer cap does not apply to the group. Each '
                    .'relationship in the ring still looks valid on its own, so nothing in a single '
                    .'referral record shows this.',
            ];
        }

        return $anomalies;
    }

    /**
     * The closed loop a referral closes, if it closes one.
     *
     * Walks up from the referrer -- "who introduced the person who introduced
     * this customer?" -- and looks for the referred customer turning up again. If
     * it does, the chain is a circle and the path taken is the ring.
     *
     * @param  array<int, int>  $introduced
     * @return list<int>|null
     */
    private function ringContaining(int $referrer, int $referred, array $introduced): ?array
    {
        $seen = [$referrer => true];
        $path = [$referrer];
        $current = $referrer;

        for ($hop = 0; $hop < self::MAX_CHAIN_DEPTH; $hop++) {
            if (! isset($introduced[$current])) {
                // Top of a chain. This referral is an ordinary introduction.
                return null;
            }

            $current = $introduced[$current];

            if ($current === $referred) {
                // The loop is closed. The ring is the chain walked plus the
                // customer it came back round to -- the referred user is not in
                // `$path`, because the walk stops the moment it arrives there.
                //
                // Sorted, so every edge of the same ring produces the same
                // members in the same order: which edge happens to be examined
                // first is an artefact of row order, and a report that changed
                // its own output between runs would be one nobody could trust.
                $ring = array_merge($path, [$referred]);
                sort($ring);

                return $ring;
            }

            if (isset($seen[$current])) {
                // Closed a loop that does not include the referred customer,
                // which is impossible if every loop has a row -- and a reason to
                // stop rather than keep walking. The ring is not this
                // referral's to report; whichever referral closed it is.
                return null;
            }

            $seen[$current] = true;
            $path[] = $current;
        }

        return null;
    }

    /**
     * @return list<array{type: string, referral_id: int|null, detail: string}>
     */
    private function selfReferrals(): array
    {
        return Referral::query()
            ->whereColumn('referrer_user_id', 'referred_user_id')
            ->get()
            ->map(fn (Referral $r): array => [
                'type' => 'self_referral',
                'referral_id' => $r->id,
                'detail' => 'A customer is recorded as having referred themselves.',
            ])
            ->all();
    }
}

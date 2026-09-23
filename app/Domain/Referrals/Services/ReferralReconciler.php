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
 */
class ReferralReconciler
{
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

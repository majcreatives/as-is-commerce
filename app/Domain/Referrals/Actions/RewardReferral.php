<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Referrals\Exceptions\ReferralNotAllowed;
use App\Domain\Referrals\Services\ReferralProgramme;
use App\Domain\Shared\Idempotency\IdempotencyGuard;
use App\Enums\CreditTransactionType;
use App\Enums\ReferralStatus;
use App\Events\ReferralRewarded;
use App\Models\Order;
use App\Models\Referral;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a qualifying purchase into credits for the referrer.
 *
 * TWO STEPS, AND THE FIRST ONE STANDS ALONE. Qualifying records that the
 * referred customer bought something real; rewarding issues the credits. They
 * are separate because a reward can legitimately fail to happen -- the cap is
 * full, the programme is switched off, the ledger write fails -- and when it
 * does, the qualifying event must survive as a fact rather than being lost.
 * A referral left at `Qualified` is a retryable state, and `referrals:reconcile`
 * is what finds it.
 *
 * THE CREDITS GO THROUGH THE ORDINARY LEDGER. `CreditLedgerService::addCredits`
 * with `ReferralCredit`, which produces a lot whose source is `referral` and
 * which is then consumed in the ledger's own deterministic order like every
 * other credit. There is no referral wallet, no bonus balance and no second
 * source of truth: this action writes a pointer to the ledger row, not a copy
 * of the amount.
 *
 * THE AMOUNT IS SNAPSHOTTED. The configured reward is read once, at the moment
 * of issue, and written onto the referral. An administrator raising the reward
 * afterwards must not revalue what was already granted -- the ledger would
 * disagree, and the customer's balance would be the honest record while the
 * referral row told a different story.
 *
 * ISSUED ONCE. The idempotency guard is keyed on the referral, the status
 * transition is checked under a row lock, and `credit_transaction_id` is unique
 * in the database. Three defences, because a duplicated reward is credits
 * created out of nothing.
 *
 * NOTHING IS EVER TAKEN BACK. This action has no inverse. If the qualifying
 * purchase is later refunded, or the referral turns out to be fraudulent, the
 * credits stay -- possibly already spent on bids that cannot be unwound. The
 * ledger is immutable, and a clawback would be a different financial act
 * needing its own design.
 */
final class RewardReferral
{
    public const OPERATION = 'referral.reward';

    public function __construct(
        private readonly IdempotencyGuard $idempotency,
        private readonly ReferralProgramme $programme,
        private readonly CreditLedgerService $credits,
    ) {}

    /**
     * Record that a referred customer made a qualifying purchase.
     *
     * Idempotent by construction: a referral already qualified or rewarded is
     * left exactly as it is, so five webhook deliveries of the same payment
     * qualify it once.
     */
    public function qualify(Referral $referral, Order $order): Referral
    {
        return DB::transaction(function () use ($referral, $order): Referral {
            $locked = $this->lock($referral);

            if (! $locked->status->canTransitionTo(ReferralStatus::Qualified)) {
                // Already qualified, already rewarded, or invalidated. None of
                // those is an error and none of them should be overwritten.
                return $locked;
            }

            // The attribution window, decided here rather than in the caller.
            //
            // This is the last point at which it can be applied without
            // unwinding something: no credits exist yet, and `Invalidated` is a
            // legal move out of `Attributed`. Refusing at the moment of
            // qualification rather than at the moment of issue also means a
            // lapsed referral is terminal instead of sitting at `Qualified`
            // forever, where `referrals:reconcile` would re-report it as an
            // unpaid reward on every run for ever.
            if (! $this->programme->withinAttributionWindow($locked)) {
                $this->lapse($locked);

                return $locked;
            }

            $locked->status = ReferralStatus::Qualified;
            $locked->qualifying_order_id = $order->id;
            $locked->qualified_at = Carbon::now();
            $locked->save();

            Log::info('Referral qualified', [
                'operation' => 'referral.qualified',
                'referral_id' => $locked->id,
                'referrer_user_id' => $locked->referrer_user_id,
                'referred_user_id' => $locked->referred_user_id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return $locked;
        });
    }

    /**
     * Issue the credits, if everything still allows it.
     *
     * Returns the referral as it now stands. A reward that cannot be issued --
     * programme switched off, no amount configured, cap reached -- leaves it at
     * `Qualified`, which is a state somebody can act on rather than a silent
     * failure.
     *
     * @throws ReferralNotAllowed When the referral has no qualifying purchase.
     */
    public function reward(Referral $referral): Referral
    {
        if ($referral->status === ReferralStatus::Rewarded) {
            return $referral;
        }

        if ($referral->status !== ReferralStatus::Qualified) {
            throw ReferralNotAllowed::notQualified($referral->status->value);
        }

        if (! $this->programme->isEnabled()) {
            $this->declineQuietly($referral, 'the referral programme is switched off');

            return $referral;
        }

        $amount = $this->programme->rewardCredits();

        if ($amount < 1) {
            $this->declineQuietly($referral, 'no reward amount is configured');

            return $referral;
        }

        if (! $this->programme->referrerHasCapacity($referral->referrer)) {
            $this->declineQuietly($referral, 'the referrer has reached the reward cap');

            return $referral;
        }

        $result = $this->idempotency->execute(
            operation: self::OPERATION,
            // Keyed on the referral: one relationship, one reward, however
            // many events arrive.
            key: 'referral:'.$referral->id,
            userId: $referral->referrer_user_id,
            work: fn (): array => $this->issue($referral, $amount),
        );

        $rewarded = Referral::findOrFail($result['referral_id']);

        // After the transaction has committed, never inside it. A message
        // written in a transaction that later rolled back would tell somebody
        // they had credits they do not have.
        if (($result['issued'] ?? false) === true) {
            ReferralRewarded::dispatch($rewarded);
        }

        return $rewarded;
    }

    /**
     * @return array{referral_id: int, issued: bool, credits: int}
     */
    private function issue(Referral $referral, int $amount): array
    {
        return DB::transaction(function () use ($referral, $amount): array {
            $locked = $this->lock($referral);

            // Re-checked inside the transaction: two events arriving together
            // both reach here, and the second must find the work already done.
            if ($locked->status === ReferralStatus::Rewarded) {
                return [
                    'referral_id' => $locked->id,
                    'issued' => false,
                    'credits' => $locked->reward_credits ?? 0,
                ];
            }

            if (! $locked->status->canTransitionTo(ReferralStatus::Rewarded)) {
                throw ReferralNotAllowed::notQualified($locked->status->value);
            }

            $wallet = $this->credits->walletFor($locked->referrer);

            // Into the ordinary ledger, as an ordinary credit. The lot this
            // produces has source `referral` and is consumed in the ledger's
            // own order -- nothing here gives referral credits a special
            // position, and nothing here creates a balance of its own.
            //
            // No expiry: the platform has not decided that referral credits
            // expire, and inventing a date would be making that decision by
            // default. The lot architecture supports one whenever it is.
            $transaction = $this->credits->addCredits(
                wallet: $wallet,
                type: CreditTransactionType::ReferralCredit,
                amount: $amount,
                expiresAt: null,
                reference: $locked,
                description: 'Referral reward',
                metadata: [
                    'referral_id' => $locked->id,
                    'referred_user_id' => $locked->referred_user_id,
                    'qualifying_order_id' => $locked->qualifying_order_id,
                ],
            );

            // The snapshot, and the pointer into the ledger. Both written in
            // the same transaction as the credits themselves, so the referral
            // can never say "rewarded" without a ledger row to show for it.
            $locked->status = ReferralStatus::Rewarded;
            $locked->reward_credits = $amount;
            $locked->credit_transaction_id = $transaction->id;
            $locked->rewarded_at = Carbon::now();
            $locked->save();

            Log::info('Referral rewarded', [
                'operation' => 'referral.rewarded',
                'referral_id' => $locked->id,
                'referrer_user_id' => $locked->referrer_user_id,
                'credits' => $amount,
                'credit_transaction_id' => $transaction->id,
                'qualifying_order_id' => $locked->qualifying_order_id,
            ]);

            return ['referral_id' => $locked->id, 'issued' => true, 'credits' => $amount];
        });
    }

    /**
     * Close a referral whose attribution window ran out.
     *
     * A rule rather than a person, so `invalidated_by` is deliberately null: no
     * member of staff decided this, and attributing it to whichever admin
     * happened to be signed in would put a name on a decision nobody made. The
     * reason carries the window that was actually applied, so the row explains
     * itself to whoever reads it next.
     *
     * Terminal rather than retryable. Once the qualifying purchase is older
     * than the window, waiting does not make it younger.
     */
    private function lapse(Referral $referral): void
    {
        $days = $this->programme->attributionWindowDays();
        $expiredAt = $this->programme->attributionExpiresAt($referral);

        $referral->status = ReferralStatus::Invalidated;
        $referral->invalidation_reason = 'The '.$days.'-day referral attribution window expired on '
            .($expiredAt?->toDateString() ?? 'an unknown date')
            .', before this customer made a qualifying purchase.';
        $referral->invalidated_at = Carbon::now();
        $referral->save();

        Log::info('Referral lapsed outside its attribution window', [
            'operation' => 'referral.window_expired',
            'referral_id' => $referral->id,
            'referrer_user_id' => $referral->referrer_user_id,
            'referred_user_id' => $referral->referred_user_id,
            'order_id' => $referral->qualifying_order_id,
            'window_days' => $days,
        ]);
    }

    /**
     * Leave a qualified referral unrewarded, and say why.
     *
     * Not an exception: the qualifying purchase happened and the record of it
     * must survive. The referral stays at `Qualified`, which is exactly the
     * state the reconciliation report looks for.
     */
    private function declineQuietly(Referral $referral, string $reason): void
    {
        Log::info('Referral reward not issued', [
            'operation' => 'referral.reward_declined',
            'referral_id' => $referral->id,
            'referrer_user_id' => $referral->referrer_user_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Re-read the referral under a row lock.
     *
     * Callers must use what comes back. Two payment events arriving together
     * would otherwise both read `Qualified` and both issue credits.
     */
    private function lock(Referral $referral): Referral
    {
        return Referral::whereKey($referral->getKey())->lockForUpdate()->firstOrFail();
    }
}

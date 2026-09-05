<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Services;

use App\Enums\OrderStatus;
use App\Enums\ReferralStatus;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;

/**
 * The programme's rules, in one place.
 *
 * WHAT QUALIFIES A REFERRAL, and this is the decision the whole stage turns on:
 *
 *   a verified, successful payment
 *   on an order that is genuinely paid
 *   which the platform can actually deliver against
 *   made by the referred customer
 *
 * Each clause excludes something specific. Registration alone qualifies
 * nothing -- an account costs nobody anything to create, and rewarding it would
 * pay for signups rather than for customers. An initialised or failed payment
 * qualifies nothing, because no money moved. A cancelled or expired order never
 * qualifies, even when a payment later succeeds against it: those orders closed,
 * and a late payment landing on one is a recovery case rather than a purchase.
 *
 * AND A BLOCKED ORDER DOES NOT QUALIFY. A payment can succeed while nothing can
 * be delivered -- another transaction took the last unit, or the checkout
 * expired mid-payment. The money is real and recorded, but the platform owes
 * that customer either an item or a refund, and paying a referrer for it would
 * be rewarding a transaction the business could not complete. This is the case
 * the brief singles out, and the narrow reading is the safe one.
 *
 * WHY `Paid` RATHER THAN `Fulfilled`. A genuine ambiguity, resolved and
 * recorded rather than assumed. Waiting for delivery would make a referral
 * reward depend on a manual warehouse process that can take days, and on the
 * customer supplying an address -- neither of which says anything about whether
 * the purchase was real. A verified payment on a deliverable order is the point
 * at which the business has been paid, so it is the point at which the referral
 * has earned something.
 *
 * NOTHING HERE READS A WALLET, AND NOTHING HERE ISSUES CREDITS. It answers
 * questions; the actions act.
 */
class ReferralProgramme
{
    /**
     * Whether the programme is running at all.
     *
     * An administrator can switch rewards off globally, which stops new
     * referrals being rewarded and changes nothing about ones already paid.
     */
    public function isEnabled(): bool
    {
        return settings()->getBool('referrals_enabled', false);
    }

    /**
     * What a qualifying referral is worth today.
     *
     * Read at the moment a reward is issued and then snapshotted onto the
     * referral. Changing this setting must never revalue a reward that has
     * already been granted.
     */
    public function rewardCredits(): int
    {
        return max(0, settings()->getInt('referral_reward_credits', 0) ?? 0);
    }

    /**
     * How many rewarded referrals one customer may accumulate.
     *
     * Zero means no cap, stated explicitly rather than left as an unbounded
     * default nobody chose. An uncapped programme is an uncapped liability,
     * and the number is an administrator's decision rather than a constant
     * buried in code.
     */
    public function maximumRewardsPerReferrer(): int
    {
        return max(0, settings()->getInt('referral_max_rewards_per_referrer', 0) ?? 0);
    }

    public function hasCap(): bool
    {
        return $this->maximumRewardsPerReferrer() > 0;
    }

    /**
     * Whether this referrer has room for another reward.
     *
     * Counted from the referral records, which are the only place a reward is
     * recorded. Never from a wallet balance: credits are spent, granted and
     * expired for a dozen reasons, and a balance says nothing about how many
     * people somebody introduced.
     */
    public function referrerHasCapacity(User $referrer): bool
    {
        if (! $this->hasCap()) {
            return true;
        }

        $rewarded = Referral::query()
            ->where('referrer_user_id', $referrer->id)
            ->where('status', ReferralStatus::Rewarded)
            ->count();

        return $rewarded < $this->maximumRewardsPerReferrer();
    }

    /**
     * Whether this order is the kind of purchase that earns a referrer credits.
     *
     * See the class comment for what each clause excludes. The order's own
     * status is asked rather than re-derived: `Paid` is reachable only through
     * a payment verified with the provider, and re-deciding that here would be
     * a second opinion on a question the orders domain already answers.
     */
    public function orderQualifies(Order $order): bool
    {
        // Paid, or somewhere further along the same path. Cancelled, expired,
        // failed and refunded orders are all excluded by this one test.
        if (! in_array($order->status, [
            OrderStatus::Paid,
            OrderStatus::Processing,
            OrderStatus::Fulfilled,
        ], true)) {
            return false;
        }

        // The money arrived but nothing could be delivered against it. Real,
        // recorded, and not a completed purchase.
        if ($order->isFulfilmentBlocked()) {
            return false;
        }

        // Belt to the braces of the status check: there must be an attempt
        // the provider actually confirmed.
        return $order->payments()->successful()->exists();
    }

    /**
     * The referral this order could qualify, if it qualifies anything.
     *
     * One per customer by construction -- `referred_user_id` is unique -- and
     * only while it is still outstanding. A referral already rewarded or
     * invalidated is not looking for a qualifying event.
     */
    public function outstandingReferralFor(Order $order): ?Referral
    {
        return Referral::query()
            ->where('referred_user_id', $order->user_id)
            ->whereIn('status', [ReferralStatus::Attributed, ReferralStatus::Qualified])
            ->first();
    }
}

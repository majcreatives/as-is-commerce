<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one referral relationship has got to.
 *
 * ```
 * Attributed → Qualified → Rewarded
 *      ↓           ↓
 *        Invalidated
 * ```
 *
 * ATTRIBUTION IS NOT A REWARD. Somebody registering through a link creates a
 * relationship and nothing else. No credits exist at that point, and the whole
 * reason this enum has four states rather than two is so the difference between
 * "somebody signed up" and "somebody actually bought something" is a fact in
 * the database rather than an assumption.
 *
 * `Qualified` exists as a separate state from `Rewarded` so a reward that could
 * not be issued -- a cap reached, rewards switched off, a ledger write that
 * failed -- leaves the qualifying event on record and retryable, rather than
 * being lost or silently completed.
 *
 * NOTHING LEAVES `Rewarded`. Credits have been issued into an immutable ledger,
 * and a status change here could not take them back. An administrator who
 * decides a rewarded referral was fraudulent records that decision; they do not
 * rewrite what happened, and they certainly do not delete credits the customer
 * may already have spent.
 */
enum ReferralStatus: string
{
    /** Somebody registered through this referrer's code. Nothing more. */
    case Attributed = 'attributed';

    /** The referred customer has made a qualifying purchase. */
    case Qualified = 'qualified';

    /** Credits have been issued to the referrer. Terminal. */
    case Rewarded = 'rewarded';

    /** Refused by an administrator, with a reason. Terminal. */
    case Invalidated = 'invalidated';

    public function label(): string
    {
        return match ($this) {
            self::Attributed => 'Signed up',
            self::Qualified => 'Qualified',
            self::Rewarded => 'Rewarded',
            self::Invalidated => 'Not eligible',
        };
    }

    /**
     * What the referrer is told this means.
     *
     * Deliberately modest about `Attributed`: somebody has joined, and nothing
     * is owed yet. Saying "pending reward" would promise something that
     * depends entirely on whether they ever buy anything.
     */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Attributed => 'Joined — no qualifying purchase yet',
            self::Qualified => 'Qualified — reward on its way',
            self::Rewarded => 'Reward received',
            self::Invalidated => 'Not eligible',
        };
    }

    public function isRewarded(): bool
    {
        return $this === self::Rewarded;
    }

    /**
     * Whether this referral could still earn its referrer credits.
     */
    public function isOutstanding(): bool
    {
        return match ($this) {
            self::Attributed, self::Qualified => true,
            self::Rewarded, self::Invalidated => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The only legal moves.
     *
     * Note what is missing. Nothing goes backwards: a qualifying purchase
     * happened or it did not, and a referral cannot be returned to "signed up"
     * to be qualified a second time. Nothing leaves `Rewarded`, because credits
     * in an immutable ledger cannot be un-issued by editing a row here.
     *
     * `Qualified → Invalidated` is allowed and `Rewarded → Invalidated` is not:
     * an administrator may refuse a referral before it is paid, and after it is
     * paid the honest record is that it was paid.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Attributed => [self::Qualified, self::Invalidated],
            self::Qualified => [self::Rewarded, self::Invalidated],
            self::Rewarded, self::Invalidated => [],
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Rewarded => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Qualified => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::Attributed => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Invalidated => 'bg-red-50 text-red-800 ring-red-200',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}

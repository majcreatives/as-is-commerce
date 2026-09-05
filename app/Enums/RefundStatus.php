<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one refund attempt has got to.
 *
 * ```
 * Pending → Processing → Succeeded
 *                      ↘ Failed
 * ```
 *
 * THIS IS NOT THE PAYMENT'S STATE AND NOT THE ORDER'S. A refund is a separate
 * financial event that points at a successful payment; it never rewrites one.
 * After a refund succeeds the original payment is still `Success` for its full
 * amount, because that is what happened. The two questions -- what was paid,
 * and what was given back -- have two separate answers and two separate
 * records.
 *
 * NOTHING REACHES `Succeeded` ON OUR SAY-SO. Not an administrator clicking a
 * button, not an accepted HTTP request, not a provider reference typed into a
 * form. The only path runs through the provider's own account of the refund,
 * fetched server-to-server. Paystack settles refunds asynchronously, so
 * `Processing` is the honest answer for as long as the provider is still
 * deciding, and `refunds:reconcile` is what later turns it into an outcome.
 *
 * `Pending` exists so a refund is on record before the provider is contacted.
 * If the call then fails, the attempt survives to be seen and retried rather
 * than vanishing with a rolled-back transaction.
 */
enum RefundStatus: string
{
    /** Recorded here. The provider has not been asked yet. */
    case Pending = 'pending';

    /** The provider accepted it and has not finished. */
    case Processing = 'processing';

    /** The provider says the money went back. Terminal. */
    case Succeeded = 'succeeded';

    /** The provider refused it, or could not be reached. Terminal. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Succeeded => 'Refunded',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the money has provably gone back.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Whether this attempt is still capable of succeeding.
     *
     * In-flight refunds hold back part of the refundable amount. Two attempts
     * for GH₵70 against a GH₵100 payment must not both be allowed to exist on
     * the chance that only one succeeds -- if both did, the platform would
     * have returned GH₵140 it never received.
     */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::Pending, self::Processing => true,
            self::Succeeded, self::Failed => false,
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
     * Note what is missing. Nothing leaves `Succeeded` -- money that went back
     * cannot be un-returned by editing a row -- and nothing leaves `Failed`
     * either: a retry is a new attempt with its own record, so the failure
     * stays visible instead of being overwritten by the success that followed
     * it.
     *
     * `Pending → Succeeded` is allowed because a provider may settle a small
     * refund immediately, and refusing to record that would mean holding a
     * refund open that the provider has already finished.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Succeeded, self::Failed],
            self::Processing => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Succeeded => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Processing => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::Pending => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::Failed => 'bg-red-50 text-red-800 ring-red-200',
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

<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Exceptions;

use DomainException;

/**
 * A referral that must not happen.
 *
 * Every message is safe to show and says which rule stopped it. None carries a
 * credential, another customer's detail, or anything about somebody's balance.
 */
final class ReferralNotAllowed extends DomainException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    public static function selfReferral(): self
    {
        return new self('You cannot refer yourself.');
    }

    public static function alreadyAttributed(): self
    {
        return new self(
            'This account already has a referrer. A referral records who introduced '
            .'somebody, and that does not change afterwards.'
        );
    }

    public static function unknownCode(): self
    {
        return new self('That referral code was not recognised.');
    }

    public static function programmeDisabled(): self
    {
        return new self('The referral programme is not running at the moment.');
    }

    public static function notQualified(string $status): self
    {
        return new self(
            "This referral is [{$status}] and has no qualifying purchase to reward."
        );
    }

    public static function noRewardConfigured(): self
    {
        return new self(
            'No referral reward amount is configured, so there is nothing to grant.'
        );
    }

    public static function capReached(int $cap): self
    {
        return new self(
            "This referrer has already received the maximum of {$cap} referral rewards."
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Referral;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A referrer's credits have been issued.
 *
 * Dispatched after the transaction that issued them has committed. A message
 * written inside one that later rolled back would tell somebody they had
 * credits they do not have, and one that threw would take the reward with it --
 * losing credits because a mail server was slow.
 *
 * Carries the referral rather than an amount: everything a listener needs --
 * who was rewarded, how much, for which purchase -- hangs off it, and reading
 * the record reads what was actually saved.
 */
final readonly class ReferralRewarded
{
    use Dispatchable;

    public function __construct(
        public Referral $referral,
    ) {}
}

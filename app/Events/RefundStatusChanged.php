<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\RefundStatus;
use App\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund moved, and its customer should hear about it.
 *
 * Dispatched after the transaction that moved it has committed. A notification
 * written inside one that later rolled back would tell somebody their money
 * was on its way back when it was not -- and of every message this platform
 * sends, that is the one it can least afford to get wrong.
 *
 * The status is carried explicitly rather than read off the refund, so a
 * listener describes the transition that actually happened rather than
 * whatever the row says by the time it is handled.
 */
final readonly class RefundStatusChanged
{
    use Dispatchable;

    public function __construct(
        public Refund $refund,
        public RefundStatus $to,
    ) {}
}

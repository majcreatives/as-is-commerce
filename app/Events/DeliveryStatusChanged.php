<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A package moved, and its customer should hear about it.
 *
 * Dispatched after the transaction that moved it has committed. A message
 * written inside one that later rolled back would tell somebody their order
 * had been delivered when it had not, and one that threw would take the
 * delivery with it -- leaving a rider's report of a completed handover
 * unrecorded because an SMTP server was slow.
 *
 * The status is carried explicitly rather than read off the delivery, so a
 * listener describes the move that actually happened rather than whatever the
 * row says by the time it is handled.
 */
final readonly class DeliveryStatusChanged
{
    use Dispatchable;

    public function __construct(
        public Delivery $delivery,
        public DeliveryStatus $to,
    ) {}
}

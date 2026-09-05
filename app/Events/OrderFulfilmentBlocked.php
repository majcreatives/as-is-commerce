<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment succeeded but nothing could be handed over.
 *
 * Its own event rather than an order transition, because in two of the three
 * cases the order's status does not change at all -- a payment landing against
 * an expired or cancelled checkout leaves it exactly where it was. What
 * changed is that the platform now holds money it cannot deliver against, and
 * that is what the customer is told.
 *
 * The message says the payment was received and the order needs attention. It
 * promises no refund, because no refund mechanism exists.
 */
final readonly class OrderFulfilmentBlocked
{
    use Dispatchable;

    public function __construct(
        public Order $order,
        public string $reason,
    ) {}
}

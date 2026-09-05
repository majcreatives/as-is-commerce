<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order moved, and its customer should hear about it.
 *
 * One event for every order transition rather than one class per status: the
 * status is already a typed enum, the recipient is always the order's owner,
 * and six classes differing only in a constant would be six places to keep the
 * same routing consistent.
 *
 * Dispatched after the transaction that moved the order has committed.
 */
final readonly class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public Order $order,
        public OrderStatus $to,
    ) {}
}

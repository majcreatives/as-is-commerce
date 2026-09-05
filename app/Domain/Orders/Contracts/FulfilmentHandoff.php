<?php

declare(strict_types=1);

namespace App\Domain\Orders\Contracts;

use App\Domain\Auction\Contracts\SettlementHandoff;
use App\Models\Order;

/**
 * How a paid order hands itself over to be physically delivered.
 *
 * An interface rather than a direct call, for the same reason
 * {@see SettlementHandoff} is one: the delivery
 * layer already depends on the orders layer -- it reads order status, moves
 * orders through their own lifecycle, and prices nothing itself -- so calling
 * back the other way would tie the two together in both directions. The orders
 * domain states what it needs; the delivery domain provides it, bound in
 * `AppServiceProvider`.
 *
 * WHAT AN IMPLEMENTATION MUST NOT DO. It must not take payment, mark anything
 * paid, move stock, consume or return credits, or alter an auction. Creating a
 * delivery records that a physical thing now needs to reach somebody, and
 * nothing else. The unit was already sold when the payment was verified.
 *
 * It must be safe to call twice. Fulfilment is idempotent and a webhook may be
 * delivered five times; a handoff that created a second delivery for one order
 * would put two packages, or two people packing the same one, into the queue.
 */
interface FulfilmentHandoff
{
    /**
     * Open the delivery this order will be sent through.
     *
     * Called inside the fulfilment transaction, with the order row already
     * locked and already marked paid. Returns the delivery's id, or null when
     * none could be opened -- which fulfilment treats as non-fatal, because an
     * order that was paid for correctly must not be un-paid by a downstream
     * failure. A missing delivery is an administrative problem; a rolled-back
     * payment is a much worse one.
     */
    public function openFor(Order $order): ?int;
}

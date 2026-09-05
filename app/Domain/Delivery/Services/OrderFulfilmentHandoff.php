<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Actions\OpenDelivery;
use App\Domain\Orders\Contracts\FulfilmentHandoff;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The delivery domain's answer to a paid order.
 *
 * Implements the orders domain's {@see FulfilmentHandoff} so the dependency
 * runs one way only: delivery knows about orders, orders knows about an
 * interface. Bound in `AppServiceProvider`, exactly as the settlement handoff
 * is.
 *
 * A FAILURE HERE MUST NEVER UN-PAY AN ORDER. The payment was verified with the
 * provider and the unit has already been sold; if a delivery cannot be opened,
 * that is an administrative problem and a much smaller one than rolling back
 * money the platform genuinely received. So this catches and logs rather than
 * throwing, and fulfilment carries on.
 *
 * The missing delivery is visible: an order that is paid with nothing to
 * deliver against it appears in the fulfilment queue's own reckoning, because
 * the queue counts paid orders rather than deliveries.
 */
class OrderFulfilmentHandoff implements FulfilmentHandoff
{
    public function __construct(
        private readonly OpenDelivery $open,
    ) {}

    public function openFor(Order $order): ?int
    {
        try {
            return $this->open->handle($order)->id;
        } catch (Throwable $e) {
            Log::error('Could not open a delivery for a paid order', [
                'operation' => 'delivery.handoff_failed',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

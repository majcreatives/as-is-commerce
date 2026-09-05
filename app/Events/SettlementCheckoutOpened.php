<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The winner's settlement checkout exists and is payable.
 *
 * Separate from {@see AuctionClosed} because the two can come apart: closing
 * stands even when the handoff cannot open an order, so a winner may be told
 * they won without there yet being anything to pay.
 */
final readonly class SettlementCheckoutOpened
{
    use Dispatchable;

    public function __construct(
        public Order $order,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Auction\Contracts\SettlementHandoff;
use App\Domain\Orders\Actions\StartSettlementCheckout;
use App\Enums\OrderSource;
use App\Models\Auction;
use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * Opens the winner's settlement checkout as an auction closes.
 *
 * WHY THE WINNER DOES NOT HAVE TO ASK. Before this existed, closing recorded a
 * winner and a deadline and then waited for them to find the auction and press
 * a button. The deadline was running the whole time. Handing the order over at
 * closure means the obligation exists the moment it is incurred, the winner
 * sees it in their orders, and the forfeit sweep has something concrete to
 * close rather than an implied debt.
 *
 * A FAILURE HERE MUST NOT REOPEN A CLOSED AUCTION. The auction has closed; the
 * highest bid won; the credits are consumed. If the order cannot be opened --
 * a deadline that had already passed, a product removed underneath it -- that
 * is logged and the closure stands. An administrator can see a
 * `PendingSettlement` auction with no order and act on it, which is far better
 * than an auction that failed to close because its paperwork did.
 *
 * Safe to call twice: the underlying action hands back the winner's existing
 * open checkout rather than opening a second one.
 */
final class AuctionSettlementHandoff implements SettlementHandoff
{
    public function __construct(
        private readonly StartSettlementCheckout $checkout,
        private readonly OrderLifecycle $orders,
    ) {}

    public function openFor(Auction $auction): ?int
    {
        $winner = $auction->winner;

        if ($winner === null) {
            return null;
        }

        try {
            $order = $this->checkout->handle($auction, $winner);
        } catch (DomainException $e) {
            Log::warning('Auction closed but its settlement checkout could not be opened', [
                'operation' => 'auction.settlement_handoff',
                'auction_id' => $auction->id,
                'winner_user_id' => $winner->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        Log::info('Settlement checkout opened at closing', [
            'operation' => 'auction.settlement_handoff',
            'auction_id' => $auction->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            // Recorded together because they are unrelated quantities and this
            // is where somebody checks that they stayed so.
            'winning_bid_credits' => $auction->winningBid?->amount_credits,
            'settlement_amount_minor' => $auction->settlement_amount_minor,
        ]);

        return $order->id;
    }

    /**
     * Close the winner's outstanding checkout.
     *
     * Cancelled rather than expired, because this is a decision taken about
     * the auction -- the deadline lapsed, or an administrator stopped it --
     * rather than the checkout's own clock running out on its own. Either way
     * it releases nothing: a settlement order holds no reservation of its own,
     * because the auction has been holding the unit since it was published.
     *
     * A settlement that was already paid is left entirely alone. That is a
     * completed transaction, and the auction's own inventory outcome has
     * already followed from it.
     */
    public function closeFor(Auction $auction, string $reason): ?int
    {
        $order = Order::query()
            ->where('auction_id', $auction->id)
            ->where('source', OrderSource::AuctionWin)
            ->awaitingPayment()
            ->first();

        if ($order === null) {
            return null;
        }

        try {
            $this->orders->cancel($order, $reason);
        } catch (DomainException $e) {
            // Somebody paid it between the query and the cancel. That is the
            // right outcome and not an error -- the settlement succeeded.
            Log::info('Settlement order could not be closed; it had already moved on', [
                'operation' => 'auction.settlement_handoff.close',
                'auction_id' => $auction->id,
                'order_id' => $order->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        Log::info('Settlement checkout closed with its auction', [
            'operation' => 'auction.settlement_handoff.close',
            'auction_id' => $auction->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'reason' => $reason,
        ]);

        return $order->id;
    }
}

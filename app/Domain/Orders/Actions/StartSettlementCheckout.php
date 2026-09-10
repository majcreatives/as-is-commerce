<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Services\CheckoutPricer;
use App\Domain\Orders\ValueObjects\CheckoutPricing;
use App\Enums\AuctionStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTransition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Open the checkout an auction winner settles through.
 *
 * WHAT THE WINNER OWES is the auction's own `settlement_amount_minor`, read
 * from its frozen snapshot, plus the delivery and tax that snapshot carries.
 * A low GHS figure, chosen per auction, and deliberately unrelated to what the
 * product sells for. A GH₵5,500 product won with 180 credits may settle at
 * GH₵100.
 *
 * WHAT THE WINNER DOES NOT OWE, and this is the point:
 *
 *   - not the Buy Now price,
 *   - not their winning bid converted into cedis -- 180 credits is not GH₵180,
 *   - not a percentage of anything,
 *   - and not their credits again. Those were consumed when the bids were
 *     placed and are gone. Nothing here charges them a second time, and
 *     nothing here gives them back.
 *
 * A settlement carries no credit discount either. Consumed credits reduce a
 * Buy Now price; they bought the winner the win, and do not also reduce what
 * winning costs. A CHECK constraint refuses a settlement order with a discount
 * on it.
 *
 * NO STOCK IS RESERVED HERE. The auction has been holding the unit since it
 * was published, and it stays held through `PendingSettlement` -- spoken for,
 * not yet sold. Reserving again would take two units off the shelf for one
 * sale.
 */
final class StartSettlementCheckout
{
    public function __construct(
        private readonly CheckoutPricer $pricer,
        private readonly AuctionLifecycle $auctions,
    ) {}

    public function handle(Auction $auction, User $winner): Order
    {
        return DB::transaction(function () use ($auction, $winner): Order {
            $locked = $this->auctions->lock($auction);

            $winningBid = $this->assertSettleable($locked, $winner);

            $existing = $this->openOrderFor($locked, $winner);

            if ($existing !== null) {
                // The same obligation, already open. Handing it back is right:
                // a winner clicking twice should reach their checkout, not
                // acquire a second one for the same auction.
                return $existing;
            }

            $pricing = $this->pricer->forSettlement($locked, $winningBid);

            $order = $this->createOrder($locked, $winner, $winningBid, $pricing);

            Log::info('Settlement checkout opened', [
                'operation' => 'checkout.settlement',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'auction_id' => $locked->id,
                'winner_id' => $winner->id,
                // Recorded side by side because they are unrelated quantities
                // and the log is where somebody checks that they stayed so.
                'winning_bid_credits' => $winningBid->amount_credits,
                'settlement_amount_minor' => $locked->settlement_amount_minor,
                'total_minor' => $pricing->total->minor,
            ]);

            return $order->fresh();
        });
    }

    /**
     * Everything that must hold before a winner is asked to settle.
     */
    private function assertSettleable(Auction $auction, User $winner): Bid
    {
        if ($auction->status !== AuctionStatus::PendingSettlement) {
            throw InvalidCheckout::auctionNotAwaitingSettlement($auction->status->label());
        }

        if ($auction->winner_user_id !== $winner->id) {
            // Not a permission failure to be reported vaguely: whoever this
            // is, they did not win, and settling somebody else's auction is
            // not a thing that can be allowed to happen.
            throw InvalidCheckout::notTheWinner();
        }

        $winningBid = $auction->winningBid;

        if ($winningBid === null) {
            throw InvalidCheckout::because(
                'This auction has a winner but no winning bid on record, so it cannot be settled.'
            );
        }

        // The auction's own settlement deadline, in server time. A winner who
        // let it lapse cannot open a checkout: the auction is on its way to
        // being forfeited, and letting them pay now would sell a unit the
        // sweep is about to put back on sale.
        if ($auction->settlement_due_at !== null
            && Carbon::now()->greaterThan($auction->settlement_due_at)) {
            throw InvalidCheckout::because(
                'The settlement deadline for this auction has passed.'
            );
        }

        return $winningBid;
    }

    /**
     * The winner's existing open checkout for this auction, if any.
     */
    private function openOrderFor(Auction $auction, User $winner): ?Order
    {
        return Order::query()
            ->where('auction_id', $auction->id)
            ->where('user_id', $winner->id)
            ->where('source', OrderSource::AuctionWin)
            ->awaitingPayment()
            ->first();
    }

    private function createOrder(
        Auction $auction,
        User $winner,
        Bid $winningBid,
        CheckoutPricing $pricing,
    ): Order {
        $product = $auction->product;

        $order = new Order;

        $order->order_number = 'AIC-O-'.now()->format('Ymd').'-'.strtoupper(Str::random(10));
        $order->user_id = $winner->id;
        $order->source = OrderSource::AuctionWin;
        $order->status = OrderStatus::PendingPayment;
        $order->auction_id = $auction->id;
        $order->winning_bid_id = $winningBid->id;

        $order->currency = $pricing->currency();
        $order->subtotal_minor = $pricing->subtotal->minor;
        $order->discount_minor = 0;
        $order->delivery_minor = $pricing->delivery->minor;
        $order->tax_minor = $pricing->tax->minor;
        $order->total_minor = $pricing->total->minor;
        $order->discount_credits = 0;
        // A settlement carries no Store Wallet portion: the payable is the
        // total, which is what the provider verifies.
        $order->store_wallet_applied_minor = $pricing->storeWalletApplied->minor;
        $order->payable_minor = $pricing->payable->minor;
        $order->pricing_snapshot = $pricing->toArray();

        // The auction's own frozen deadline, so the terms the winner was
        // shown while bidding are the terms they are held to.
        $order->payment_due_at = $auction->settlement_due_at
            ?? Carbon::now()->addMinutes(max(1, $auction->rules()->checkoutDeadlineMinutes));

        $order->placed_at = Carbon::now();
        // The auction is holding the unit already.
        $order->holds_reservation = false;
        $order->save();

        $item = new OrderItem;
        $item->order_id = $order->id;
        $item->product_id = $product->id;
        $item->product_name_snapshot = $product->name;
        $item->sku_snapshot = $product->sku;
        $item->quantity = 1;
        // The settlement amount, not the product's Buy Now price. What the
        // line cost is what this auction settles at.
        $item->unit_price_minor = $pricing->subtotal->minor;
        $item->discount_minor = 0;
        $item->line_total_minor = $pricing->subtotal->minor;
        // Kept as evidence of what was won with, never of what is owed.
        $item->metadata = [
            'winning_bid_id' => $winningBid->id,
            'winning_bid_credits' => $winningBid->amount_credits,
        ];
        $item->save();

        OrderTransition::create([
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => OrderStatus::PendingPayment,
            'reason' => "Settlement checkout opened for auction #{$auction->id}, won with "
                ."{$winningBid->amount_credits} credits.",
            'caused_by' => $winner->id,
        ]);

        return $order;
    }
}

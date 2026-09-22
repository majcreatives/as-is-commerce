<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Enums\AuctionClosureReason;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Events\AuctionClosed;
use App\Events\AuctionForfeited;
use App\Events\AuctionSoldViaBuyNow;
use App\Events\BidAccepted;
use App\Events\DeliveryStatusChanged;
use App\Events\OrderFulfilmentBlocked;
use App\Events\OrderStatusChanged;
use App\Events\ReferralRewarded;
use App\Events\RefundStatusChanged;
use App\Events\SettlementCheckoutOpened;
use App\Models\Auction;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every message this platform sends, and who gets it.
 *
 * One subscriber rather than a listener class per event, deliberately. This is
 * the notification policy, and having it in one file means the wording, the
 * recipients and the idempotency keys can be read against each other -- which
 * is exactly what somebody checking "does a losing bidder ever get told their
 * credits come back" needs to do.
 *
 * TERMINOLOGY IS LOAD-BEARING HERE. Credits are always a count and never
 * money. A settlement is always GH₵ and never a conversion of anybody's bid. A
 * blocked payment is always "received, and needs attention" and never a
 * promised refund. These messages are the platform's voice on the three things
 * it is easiest to get wrong.
 *
 * PRIVACY. A bidder is never named to another bidder, and a Buy Now buyer is
 * never named to the people they outbid. Participants are told what happened
 * to the auction, not who did it.
 *
 * NOTHING HERE MAY THROW INTO A CALLER. Every handler is wrapped: a business
 * event has already committed by the time it reaches this class, and a failure
 * to describe it must not be allowed to look like a failure to do it.
 */
class NotificationSubscriber
{
    public function __construct(
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            BidAccepted::class => 'onBidAccepted',
            AuctionClosed::class => 'onAuctionClosed',
            AuctionSoldViaBuyNow::class => 'onSoldViaBuyNow',
            AuctionForfeited::class => 'onAuctionForfeited',
            SettlementCheckoutOpened::class => 'onSettlementOpened',
            OrderStatusChanged::class => 'onOrderStatusChanged',
            OrderFulfilmentBlocked::class => 'onFulfilmentBlocked',
            RefundStatusChanged::class => 'onRefundStatusChanged',
            ReferralRewarded::class => 'onReferralRewarded',
            DeliveryStatusChanged::class => 'onDeliveryStatusChanged',
        ];
    }

    // ---------------------------------------------------------------- Bids

    public function onBidAccepted(BidAccepted $event): void
    {
        $this->guard(function () use ($event): void {
            $bid = $event->bid;
            $auction = $bid->auction;
            $name = $auction->product->name;

            // Under the cumulative model a bid is the gap it closes, and what
            // matters to the bidder is where it leaves them -- which is always
            // the lead, since every bid lands one step ahead. Said, so the
            // message is not "a bid of 2" with no hint that it won the lead.
            $leadNote = $auction->rules()->bidModel->isCumulative()
                ? " You now lead with {$this->credits($bid->rankingValue())}."
                : '';

            $this->notifications->send(
                recipient: $bid->user,
                type: NotificationType::BidPlaced,
                title: 'Bid placed',
                message: "Your bid of {$this->credits($bid->amount_credits)} on {$name} was "
                    ."accepted.{$leadNote} Those Credits are now consumed and are not refunded.",
                // The bid's own id: one bid, one confirmation, however many
                // times a retried request is delivered.
                eventKey: "bid.placed:{$bid->id}",
                actionUrl: route('auctions.show', $auction, absolute: false),
                actionLabel: 'View the auction',
                context: ['auction_id' => $auction->id, 'bid_id' => $bid->id],
            );

            $this->notifyOutbid($event);
            $this->notifyExtended($event);
        }, 'bid_accepted');
    }

    /**
     * Tell whoever this bid displaced.
     *
     * Only the amount to beat is disclosed -- never who beat them. A bidder
     * knowing another bidder's identity is not something this platform offers,
     * and an outbid message is not the place to start.
     */
    private function notifyOutbid(BidAccepted $event): void
    {
        $previous = $event->previousHighest;

        if ($previous === null || $previous->user_id === $event->bid->user_id) {
            return;
        }

        $auction = $event->bid->auction;
        $model = $auction->rules()->bidModel;

        // What happened, in the model's own terms. Under the cumulative model
        // nobody "bid higher": somebody closed the gap and took the lead, and
        // the figure that matters is the new total to beat, not the credits
        // that bid happened to add.
        $what = $model->isCumulative() ? 'taken the lead on' : 'bid higher on';

        $this->notifications->send(
            recipient: $previous->user,
            type: NotificationType::Outbid,
            title: 'You were outbid',
            message: "Someone has {$what} {$auction->product->name}. The {$model->leaderNoun()} is now "
                ."{$this->credits($event->bid->rankingValue())}. Your earlier Credits stay "
                .'consumed whether or not you bid again.',
            // Keyed on the displacing bid and the displaced person, so one
            // bid produces one message per person it overtook.
            eventKey: "bid.outbid:{$event->bid->id}:{$previous->user_id}",
            actionUrl: route('auctions.show', $auction, absolute: false),
            actionLabel: 'Bid again',
            context: ['auction_id' => $auction->id],
        );
    }

    /**
     * Tell the other participants the clock moved.
     *
     * Extension is anti-sniping: it gives everybody else a chance to bid
     * higher. The message says so, because a bidder who does not understand
     * why an auction is still running may assume something has gone wrong.
     */
    private function notifyExtended(BidAccepted $event): void
    {
        if ($event->extendedBySeconds <= 0) {
            return;
        }

        $auction = $event->bid->auction;

        foreach ($this->participants($auction, exceptUserId: $event->bid->user_id) as $participant) {
            $this->notifications->send(
                recipient: $participant,
                type: NotificationType::AuctionExtended,
                title: 'Auction extended',
                message: "A late bid extended {$auction->product->name} by "
                    ."{$event->extendedBySeconds} seconds. There is still time to bid higher.",
                eventKey: "auction.extended:{$auction->id}:{$auction->extensions_applied}:{$participant->id}",
                actionUrl: route('auctions.show', $auction, absolute: false),
                actionLabel: 'View the auction',
                context: ['auction_id' => $auction->id],
            );
        }
    }

    // ------------------------------------------------------ How it ended

    public function onAuctionClosed(AuctionClosed $event): void
    {
        $this->guard(function () use ($event): void {
            $auction = $event->auction;

            if ($auction->winner_user_id !== null) {
                $this->notifyWinner($auction);
            }

            $this->notifyLosers($auction, NotificationType::AuctionLost, function (Auction $a): string {
                $winning = $a->winningBid?->rankingValue();

                return $winning === null
                    ? "The auction for {$a->product->name} has ended without a winning bid. The "
                        .'Credits you committed remain consumed.'
                    : "The auction for {$a->product->name} has ended. You did not win. The "
                        ."{$a->rules()->bidModel->winningFigure()} was {$this->credits($winning)}."
                        .$this->potTargetNote($a)
                        .' The Credits you committed remain consumed and are not refunded.';
            });
        }, 'auction_closed');
    }

    /**
     * Tell the winner what they won and what they now owe.
     *
     * The two figures are named separately and in their own units, because
     * conflating them is the single most damaging thing a message on this
     * platform could do. The bid is Credits. The settlement is GH₵. The
     * message never suggests one became the other.
     */
    private function notifyWinner(Auction $auction): void
    {
        $winner = $auction->winner;

        if ($winner === null) {
            return;
        }

        // Not nullsafe: an auction with a winner always has the bid they won
        // with. The guard above establishes the winner, and a CHECK constraint
        // refuses a row carrying one without the other.
        $credits = $this->credits($auction->winningBid->rankingValue());
        $settlement = $this->money($auction->settlementAmount()->format());
        $deadline = $auction->settlement_due_at
            ?->timezone(settings()->getString('display_timezone', 'UTC'))
            ->format('j M Y, H:i');

        $message = "You won the auction for {$auction->product->name}. Your "
            ."{$auction->rules()->bidModel->winningFigure()} was {$credits}, which are already consumed."
            .$this->potTargetNote($auction)
            .' Your Auction Settlement Amount is '
            ."{$settlement}, payable separately.";

        if ($deadline !== null) {
            $message .= " Please settle by {$deadline}.";
        }

        $this->notifications->send(
            recipient: $winner,
            type: NotificationType::AuctionWon,
            title: 'You won the auction',
            message: $message,
            eventKey: "auction.won:{$auction->id}",
            actionUrl: route('auctions.show', $auction, absolute: false),
            actionLabel: 'Settle this auction',
            context: ['auction_id' => $auction->id],
        );
    }

    public function onSoldViaBuyNow(AuctionSoldViaBuyNow $event): void
    {
        $this->guard(function () use ($event): void {
            $auction = $event->auction;

            // The buyer. Told what they bought, and that it ended the auction.
            $this->notifications->send(
                recipient: $event->buyer,
                type: NotificationType::AuctionSoldViaBuyNow,
                title: 'Purchase complete',
                message: "You bought {$auction->product->name} outright. This ended the auction "
                    .'immediately, and the product is yours subject to normal fulfilment.',
                eventKey: "auction.buy_now.buyer:{$auction->id}",
                actionUrl: route('orders.index', absolute: false),
                actionLabel: 'View your orders',
                context: ['auction_id' => $auction->id],
            );

            // Everybody who was bidding. Told that it sold, never to whom.
            $this->notifyLosers($auction, NotificationType::AuctionSoldViaBuyNow, fn (Auction $a): string => "Sold via Buy Now. The auction for {$a->product->name} ended because the product "
                .'was bought outright. The Credits you committed remain consumed and are not '
                .'refunded.',
                exceptUserId: $event->buyer->id,
            );
        }, 'sold_via_buy_now');
    }

    public function onAuctionForfeited(AuctionForfeited $event): void
    {
        $this->guard(function () use ($event): void {
            $auction = $event->auction;
            $winner = $auction->winner;

            if ($winner === null) {
                return;
            }

            $this->notifications->send(
                recipient: $winner,
                type: NotificationType::SettlementForfeited,
                title: 'Settlement deadline passed',
                message: "The settlement deadline for {$auction->product->name} has passed and the "
                    .'auction has been forfeited. The product is available again. The Credits you '
                    .'bid remain consumed.',
                eventKey: "auction.forfeited:{$auction->id}",
                actionUrl: route('auctions.show', $auction, absolute: false),
                actionLabel: 'View the auction',
                context: ['auction_id' => $auction->id],
            );
        }, 'auction_forfeited');
    }

    // ---------------------------------------------------------- Settlement

    public function onSettlementOpened(SettlementCheckoutOpened $event): void
    {
        $this->guard(function () use ($event): void {
            $order = $event->order;

            $this->notifications->send(
                recipient: $order->user,
                type: NotificationType::SettlementCreated,
                title: 'Settlement ready',
                message: "Your settlement for {$order->item()?->product_name_snapshot} is ready. "
                    ."The Auction Settlement Amount is {$this->money($order->total()->format())}. "
                    .'Your bid Credits are already consumed and are not part of this amount.',
                eventKey: "settlement.created:{$order->id}",
                actionUrl: route('checkout.show', $order, absolute: false),
                actionLabel: 'Go to checkout',
                context: ['order_id' => $order->id, 'auction_id' => $order->auction_id],
            );
        }, 'settlement_opened');
    }

    // -------------------------------------------------------------- Orders

    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        $this->guard(function () use ($event): void {
            $order = $event->order;
            $line = $order->item();
            $item = $line === null ? 'your order' : $line->product_name_snapshot;

            [$type, $title, $message, $label] = match ($event->to) {
                OrderStatus::Paid => [
                    NotificationType::OrderPaymentSuccess,
                    'Payment received',
                    "We have confirmed your payment of {$this->money($order->total()->format())} "
                        ."for {$item}. Order {$order->order_number} is confirmed.",
                    'View your order',
                ],
                OrderStatus::Fulfilled => [
                    NotificationType::OrderFulfilled,
                    'Order fulfilled',
                    "Order {$order->order_number} for {$item} has been fulfilled.",
                    'View your order',
                ],
                OrderStatus::PaymentFailed => [
                    NotificationType::OrderPaymentFailed,
                    'Payment failed',
                    "The payment for order {$order->order_number} did not go through. Nothing has "
                        .'been charged. You can start a new checkout if you still want it.',
                    'View your order',
                ],
                OrderStatus::PaymentExpired => [
                    NotificationType::OrderPaymentExpired,
                    'Checkout expired',
                    "The checkout for order {$order->order_number} expired before payment. "
                        .'Anything it was holding is back on sale.',
                    'View your order',
                ],
                OrderStatus::Cancelled => [
                    NotificationType::OrderCancelled,
                    'Order cancelled',
                    "Order {$order->order_number} has been cancelled.",
                    'View your order',
                ],
                // Processing is internal progress, not news. Telling somebody
                // their paid order is "being prepared" adds a message without
                // adding information.
                default => [null, '', '', null],
            };

            if ($type === null) {
                return;
            }

            $this->notifications->send(
                recipient: $order->user,
                type: $type,
                title: $title,
                message: $message,
                // The order and the status it reached: a repeated webhook
                // reaches the same pair and writes nothing the second time.
                eventKey: "order.status:{$order->id}:{$event->to->value}",
                actionUrl: route('orders.show', $order, absolute: false),
                actionLabel: $label,
                context: ['order_id' => $order->id],
            );
        }, 'order_status_changed');
    }

    /**
     * The platform has money it cannot deliver against.
     *
     * The message says exactly that and nothing more. It does not promise a
     * refund, because no refund mechanism exists and saying otherwise would be
     * a commitment the platform cannot keep. It does not explain which
     * concurrent transaction won, because that is our problem rather than the
     * customer's.
     */
    public function onFulfilmentBlocked(OrderFulfilmentBlocked $event): void
    {
        $this->guard(function () use ($event): void {
            $order = $event->order;

            $this->notifications->send(
                recipient: $order->user,
                type: NotificationType::OrderFulfilmentBlocked,
                title: 'Your order needs attention',
                message: "We received your payment for order {$order->order_number}, but we cannot "
                    .'complete it because the item is no longer available for this order. Our '
                    .'support team has been notified and will be in touch.',
                eventKey: "order.blocked:{$order->id}",
                actionUrl: route('orders.show', $order, absolute: false),
                actionLabel: 'View your order',
                context: ['order_id' => $order->id],
            );
        }, 'fulfilment_blocked');
    }

    // ------------------------------------------------------------- Refunds

    /**
     * Money the platform took is going back, or has, or could not.
     *
     * THE HARDEST MESSAGE ON THIS PLATFORM TO GET RIGHT, and the rules are
     * strict:
     *
     *   Never say a refund completed until the provider has confirmed it.
     *   `RefundStatus::Succeeded` is the only state that produces that
     *   sentence, and the only way into it is the provider's own account.
     *   Saying it early cannot be taken back.
     *
     *   Never say bid credits are coming back, because they are not. A
     *   customer who bid and then bought outright is told plainly that the
     *   cedis are returning and the Credits stay consumed -- otherwise a
     *   refund notice reads like a reversal of everything.
     *
     *   Never promise a timeline. The platform does not control when a bank
     *   posts a credit, and inventing "3-5 working days" would be a commitment
     *   somebody else has to keep.
     */
    public function onRefundStatusChanged(RefundStatusChanged $event): void
    {
        $this->guard(function () use ($event): void {
            $refund = $event->refund;
            $order = $refund->order;
            $amount = $this->money($refund->amount()->format());

            [$type, $title, $message] = match ($event->to) {
                RefundStatus::Processing => [
                    NotificationType::RefundStarted,
                    'Refund started',
                    "We have started returning {$amount} for order {$order->order_number}, because "
                        .$refund->reason->customerDescription().'. We will confirm when it is done.',
                ],
                RefundStatus::Succeeded => [
                    NotificationType::RefundCompleted,
                    'Refund completed',
                    "We have returned {$amount} for order {$order->order_number} to the payment "
                        .'method you used.',
                ],
                RefundStatus::Failed => [
                    NotificationType::RefundFailed,
                    'Refund could not be completed',
                    "We tried to return {$amount} for order {$order->order_number} and it did not "
                        .'go through. No money has been taken from you, and our support team has '
                        .'been notified.',
                ],
                // Pending is internal: the refund is on record here and the
                // provider has not been asked yet. Telling a customer at that
                // point would be announcing an intention, not an event.
                RefundStatus::Pending => [null, '', ''],
            };

            if ($type === null) {
                return;
            }

            $this->notifications->send(
                recipient: $order->user,
                type: $type,
                title: $title,
                message: $message.$this->creditsStayConsumed($refund),
                // The refund and the state it reached: a repeated sweep
                // reaches the same pair and writes nothing the second time.
                eventKey: "refund.status:{$refund->id}:{$event->to->value}",
                actionUrl: route('orders.show', $order, absolute: false),
                actionLabel: 'View your order',
                context: ['order_id' => $order->id, 'refund_id' => $refund->id],
            );
        }, 'refund_status_changed');
    }

    /**
     * The sentence that stops a refund reading like a reversal.
     *
     * Only where credits were actually involved -- an order tied to an auction
     * -- because on a plain catalog purchase there were none, and mentioning
     * them would raise a question nobody asked.
     */
    private function creditsStayConsumed(Refund $refund): string
    {
        if ($refund->order->auction_id === null) {
            return '';
        }

        return ' Any Credits you spent bidding remain consumed; this returns cedis only.';
    }

    // ------------------------------------------------------------ Delivery

    /**
     * A package moved, and the customer wants to know.
     *
     * NEVER AHEAD OF THE FACT. Each of these is dispatched from a committed
     * transition, so "your order has been dispatched" is sent because a member
     * of staff recorded a dispatch, and "delivered" because somebody recorded
     * a handover. There is no path that sends either on the strength of an
     * intention, and none that sends them early because a screen was open.
     *
     * A FAILED DELIVERY PROMISES NOTHING. It says an attempt did not work and
     * that we are arranging another, in terms that do not accuse the customer
     * of anything -- a package refused at the door and one nobody answered are
     * the same message here. It offers no refund: whether one is owed is a
     * person's decision made through the refund workflow, and a delivery
     * notice is not the place to pre-empt it.
     */
    public function onDeliveryStatusChanged(DeliveryStatusChanged $event): void
    {
        $this->guard(function () use ($event): void {
            $delivery = $event->delivery;
            $order = $delivery->order;
            $item = $this->itemName($order);

            [$type, $title, $message] = match ($event->to) {
                DeliveryStatus::Preparing => [
                    NotificationType::DeliveryPreparing,
                    'Preparing your order',
                    "We have started preparing {$item} for delivery.",
                ],
                DeliveryStatus::ReadyForDispatch => [
                    NotificationType::DeliveryReady,
                    'Packed and ready',
                    "{$item} is packed and waiting to go out.",
                ],
                DeliveryStatus::Dispatched => [
                    NotificationType::DeliveryDispatched,
                    'On its way',
                    "{$item} has left us and is on its way to "
                        .($delivery->city ?? 'you').'.',
                ],
                DeliveryStatus::OutForDelivery => [
                    NotificationType::DeliveryOutForDelivery,
                    'Out for delivery',
                    "{$item} is out for delivery today. Please keep your phone nearby.",
                ],
                DeliveryStatus::Delivered => [
                    NotificationType::DeliveryDelivered,
                    'Delivered',
                    "{$item} has been delivered. Thank you for shopping with us.",
                ],
                DeliveryStatus::DeliveryFailed => [
                    NotificationType::DeliveryFailed,
                    'Delivery attempt unsuccessful',
                    "We tried to deliver {$item} and could not: "
                        .($delivery->failure_reason?->customerDescription()
                            ?? 'the delivery could not be completed')
                        .'. Our team will be in touch to arrange another attempt.',
                ],
                // Pending is the moment the package is queued, which the
                // payment confirmation has already told them about. Cancelled
                // is an operational decision somebody will explain properly;
                // an automated line here would raise more questions than it
                // answered.
                DeliveryStatus::Pending, DeliveryStatus::Cancelled => [null, '', ''],
            };

            if ($type === null) {
                return;
            }

            $this->notifications->send(
                recipient: $order->user,
                type: $type,
                title: $title,
                message: $message,
                // The delivery and the state it reached: a repeated request
                // reaches the same pair and writes nothing the second time.
                eventKey: "delivery.status:{$delivery->id}:{$event->to->value}",
                actionUrl: route('orders.tracking', $order, absolute: false),
                actionLabel: 'Track this order',
                context: ['order_id' => $order->id, 'delivery_id' => $delivery->id],
            );
        }, 'delivery_status_changed');
    }

    /**
     * What to call the thing being delivered.
     *
     * The snapshot from the order line, so a product renamed since does not
     * make an old delivery message describe something the customer never
     * bought.
     */
    private function itemName(Order $order): string
    {
        $line = $order->item();

        return $line === null ? 'your order' : $line->product_name_snapshot;
    }

    // ----------------------------------------------------------- Referrals

    /**
     * Somebody the customer introduced made a purchase, and credits arrived.
     *
     * CREDITS, NOT MONEY. The message says a count and never a cedis figure --
     * a referral reward is platform credits, cannot be withdrawn, and calling
     * it earnings would be describing a payout that does not exist.
     *
     * The referred customer is never named. The referrer knows they invited
     * people; which of them bought something is that person's business.
     */
    public function onReferralRewarded(ReferralRewarded $event): void
    {
        $this->guard(function () use ($event): void {
            $referral = $event->referral;
            $credits = $this->credits($referral->reward_credits ?? 0);

            $this->notifications->send(
                recipient: $referral->referrer,
                type: NotificationType::ReferralRewarded,
                title: 'Referral reward received',
                message: "Someone you invited made their first purchase, so {$credits} have been "
                    .'added to your balance. They are bidding credits, not cash, and cannot be '
                    .'withdrawn.',
                // The referral and the event, never the wording.
                eventKey: "referral.rewarded:{$referral->id}",
                actionUrl: route('referrals.index', absolute: false),
                actionLabel: 'View your referrals',
                context: ['referral_id' => $referral->id],
            );
        }, 'referral_rewarded');
    }

    // ----------------------------------------------------------- Internals

    /**
     * Everybody who bid on this auction, once each.
     *
     * From the bid records rather than a list passed in, so it cannot go stale
     * between the event and the message.
     *
     * @return Collection<int, User>
     */
    private function participants(Auction $auction, ?int $exceptUserId = null): Collection
    {
        $ids = $auction->bids()
            ->counting()
            ->when($exceptUserId !== null, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->distinct()
            ->pluck('user_id');

        return User::whereIn('id', $ids)->get();
    }

    /**
     * Tell everyone who bid and did not win.
     *
     * @param  callable(Auction): string  $message
     */
    private function notifyLosers(
        Auction $auction,
        NotificationType $type,
        callable $message,
        ?int $exceptUserId = null,
    ): void {
        $text = $message($auction);

        foreach ($this->participants($auction, $exceptUserId ?? $auction->winner_user_id) as $loser) {
            $this->notifications->send(
                recipient: $loser,
                type: $type,
                title: $type === NotificationType::AuctionSoldViaBuyNow
                    ? 'Sold via Buy Now'
                    : 'Auction ended',
                message: $text,
                eventKey: "auction.ended:{$auction->id}:{$type->value}:{$loser->id}",
                actionUrl: route('auctions.show', $auction, absolute: false),
                actionLabel: 'View the auction',
                context: ['auction_id' => $auction->id],
            );
        }
    }

    /**
     * Credits, written as a count and never with a currency symbol.
     *
     * Every integer this class handles -- `amount_credits`, `rankingValue()`,
     * `reward_credits` -- is stored in subcredits, exactly like every other
     * post-redenomination credit column. Routed through {@see CreditAmount}
     * so a notification reads the same figure the room and the wallet show,
     * rather than the raw stored count.
     */
    private function credits(int $amount): string
    {
        return CreditAmount::fromSubcredits($amount)->format();
    }

    /**
     * The honest reason a normal close happened early, or nothing.
     *
     * docs/PLAN_POT_TARGET_BIDDING.md, step 5: "the target explained
     * honestly." Empty for every auction that ran its ordinary course --
     * which is every auction until an administrator sets a target at all --
     * so this changes nothing about a message that does not apply to. Never
     * says what the target was: the pot is an aggregate across everybody who
     * bid, and stating it here would invite a reader to work out how much
     * other people spent, which is not this platform's business to disclose.
     */
    private function potTargetNote(Auction $auction): string
    {
        if ($auction->closure_reason !== AuctionClosureReason::PotTargetReached) {
            return '';
        }

        return ' This auction closed early because enough bidders joined in to reach its target.';
    }

    /**
     * Money, written with the symbol the settings say to use.
     */
    private function money(string $formatted): string
    {
        return settings()->getString('currency_symbol', 'GH₵').$formatted;
    }

    /**
     * Run a handler, and swallow anything it throws.
     *
     * The business event committed before this class saw it. A message that
     * cannot be composed or delivered is a problem worth logging and no reason
     * whatever to make a completed bid, payment or closure look like a
     * failure.
     */
    private function guard(callable $work, string $operation): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('Notification handling failed', [
                'operation' => 'notification.'.$operation,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

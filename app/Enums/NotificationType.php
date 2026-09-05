<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a notification is about.
 *
 * Stable machine-readable identifiers, because these are stored on every
 * notification row and read back by queries, filters and tests. Renaming one
 * rewrites history, so they are chosen to describe the event rather than the
 * wording of the message, which may change freely.
 *
 * TWO CATEGORIES, AND THE DIFFERENCE MATTERS.
 *
 *   Transactional  Something happened to this person's money, their order or
 *                  their obligation. They are told regardless of preference:
 *                  silently withholding "we took your payment but cannot
 *                  fulfil your order" would be a worse experience than any
 *                  amount of noise, not a quieter one.
 *
 *   Engagement     Useful, not essential. A bid accepted, being overtaken, an
 *                  auction extended. A customer may switch these off.
 *
 * WHAT IS DELIBERATELY ABSENT. There is no "auction ending soon" and no
 * "settlement deadline approaching" here. Both need a threshold -- how soon is
 * soon -- and no such value has been decided. Seeding one would make that
 * decision by default and it would become the number everyone designs around,
 * exactly as an invented minimum bid would have. They are recorded as pending
 * product decisions instead.
 */
enum NotificationType: string
{
    // ---- Bidding ---------------------------------------------------------
    case BidPlaced = 'auction.bid_placed';
    case Outbid = 'auction.outbid';
    case AuctionExtended = 'auction.extended';

    // ---- How an auction ended --------------------------------------------
    case AuctionWon = 'auction.won';
    case AuctionLost = 'auction.lost';
    case AuctionSoldViaBuyNow = 'auction.buy_now_completed';

    // ---- Settlement ------------------------------------------------------
    case SettlementCreated = 'auction.settlement_created';
    case SettlementForfeited = 'auction.settlement_expired';

    // ---- Delivery --------------------------------------------------------
    case DeliveryPreparing = 'delivery.preparing';
    case DeliveryReady = 'delivery.ready';
    case DeliveryDispatched = 'delivery.dispatched';
    case DeliveryOutForDelivery = 'delivery.out_for_delivery';
    case DeliveryDelivered = 'delivery.delivered';
    case DeliveryFailed = 'delivery.failed';

    // ---- Referrals -------------------------------------------------------
    case ReferralRewarded = 'referral.rewarded';

    // ---- Refunds ---------------------------------------------------------
    case RefundStarted = 'refund.started';
    case RefundCompleted = 'refund.completed';
    case RefundFailed = 'refund.failed';

    // ---- Orders and payment ----------------------------------------------
    case OrderPaymentSuccess = 'order.payment_success';
    case OrderPaymentFailed = 'order.payment_failed';
    case OrderFulfilmentBlocked = 'order.fulfillment_blocked';
    case OrderFulfilled = 'order.fulfilled';
    case OrderCancelled = 'order.cancelled';
    case OrderPaymentExpired = 'order.payment_expired';

    public function label(): string
    {
        return match ($this) {
            self::BidPlaced => 'Bid placed',
            self::Outbid => 'You were outbid',
            self::AuctionExtended => 'Auction extended',
            self::AuctionWon => 'You won an auction',
            self::AuctionLost => 'Auction ended',
            self::AuctionSoldViaBuyNow => 'Sold via Buy Now',
            self::SettlementCreated => 'Settlement ready',
            self::SettlementForfeited => 'Settlement expired',
            self::DeliveryPreparing => 'Preparing your order',
            self::DeliveryReady => 'Packed and ready',
            self::DeliveryDispatched => 'On its way',
            self::DeliveryOutForDelivery => 'Out for delivery',
            self::DeliveryDelivered => 'Delivered',
            self::DeliveryFailed => 'Delivery attempt unsuccessful',
            self::ReferralRewarded => 'Referral reward received',
            self::RefundStarted => 'Refund started',
            self::RefundCompleted => 'Refund completed',
            self::RefundFailed => 'Refund could not be completed',
            self::OrderPaymentSuccess => 'Payment received',
            self::OrderPaymentFailed => 'Payment failed',
            self::OrderFulfilmentBlocked => 'Order needs attention',
            self::OrderFulfilled => 'Order fulfilled',
            self::OrderCancelled => 'Order cancelled',
            self::OrderPaymentExpired => 'Checkout expired',
        };
    }

    /**
     * Whether this must reach the customer whatever their preferences say.
     *
     * True for anything about money that has moved, an obligation they have
     * incurred, or an order that cannot proceed. A preference switch is for
     * reducing noise, not for making the platform quietly stop telling someone
     * that it has their money.
     */
    public function isTransactional(): bool
    {
        return match ($this) {
            self::AuctionWon,
            self::SettlementCreated,
            self::SettlementForfeited,
            // A customer waiting for a physical thing is always told when it
            // leaves, when it arrives, and when an attempt did not work. The
            // step-by-step detail in between is switchable; these are not.
            self::DeliveryDispatched,
            self::DeliveryDelivered,
            self::DeliveryFailed,
            self::ReferralRewarded,
            self::RefundStarted,
            self::RefundCompleted,
            self::RefundFailed,
            self::OrderPaymentSuccess,
            self::OrderPaymentFailed,
            self::OrderFulfilmentBlocked,
            self::OrderFulfilled,
            self::OrderCancelled,
            self::OrderPaymentExpired => true,

            self::BidPlaced,
            self::Outbid,
            self::AuctionExtended,
            self::AuctionLost,
            self::AuctionSoldViaBuyNow,
            self::DeliveryPreparing,
            self::DeliveryReady,
            self::DeliveryOutForDelivery => false,
        };
    }

    /**
     * The preference switch that governs this type.
     *
     * Transactional types report their own category but are never consulted
     * against it -- {@see self::isTransactional()} settles them first.
     */
    public function category(): NotificationCategory
    {
        return match ($this) {
            self::BidPlaced, self::Outbid, self::AuctionExtended => NotificationCategory::Bidding,
            self::AuctionLost, self::AuctionSoldViaBuyNow => NotificationCategory::AuctionResults,
            self::DeliveryPreparing, self::DeliveryReady,
            self::DeliveryOutForDelivery => NotificationCategory::DeliveryUpdates,
            default => NotificationCategory::Transactional,
        };
    }

    /**
     * Whether this type is also worth an email.
     *
     * Narrow on purpose. An email for every bid on a busy auction is a way to
     * get an address marked as spam; an email about money is worth sending.
     */
    public function warrantsEmail(): bool
    {
        return match ($this) {
            self::AuctionWon,
            self::SettlementCreated,
            self::SettlementForfeited,
            self::RefundCompleted,
            self::RefundFailed,
            self::OrderPaymentSuccess,
            self::OrderFulfilmentBlocked,
            self::OrderFulfilled,
            // A package leaving, arriving, or failing to arrive is worth an
            // email. The steps in between are not: a message for every stage
            // of one order is how an address gets marked as spam.
            self::DeliveryDispatched,
            self::DeliveryDelivered,
            self::DeliveryFailed,
            self::ReferralRewarded => true,
            // Deliberately no email when a refund starts. It is an
            // acknowledgement rather than an outcome, and the outcome is
            // coming; two emails for one refund is one too many.
            default => false,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::AuctionWon, self::OrderPaymentSuccess, self::OrderFulfilled => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::OrderFulfilmentBlocked, self::OrderPaymentFailed => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::SettlementCreated, self::SettlementForfeited => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::RefundCompleted => 'bg-violet-50 text-violet-800 ring-violet-200',
            self::ReferralRewarded => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::RefundStarted => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::RefundFailed => 'bg-red-50 text-red-800 ring-red-200',
            self::Outbid => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::DeliveryDelivered => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::DeliveryDispatched, self::DeliveryOutForDelivery => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::DeliveryFailed => 'bg-red-50 text-red-800 ring-red-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}

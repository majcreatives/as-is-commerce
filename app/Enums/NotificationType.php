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
            self::AuctionSoldViaBuyNow => false,
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
            self::OrderPaymentSuccess,
            self::OrderFulfilmentBlocked,
            self::OrderFulfilled => true,
            default => false,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::AuctionWon, self::OrderPaymentSuccess, self::OrderFulfilled => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::OrderFulfilmentBlocked, self::OrderPaymentFailed => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::SettlementCreated, self::SettlementForfeited => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::Outbid => 'bg-accent-50 text-accent-900 ring-accent-200',
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

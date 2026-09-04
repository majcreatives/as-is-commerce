<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an order came to exist.
 *
 * Recorded explicitly rather than inferred from which columns happen to be
 * filled in. The two paths owe fundamentally different amounts -- a Buy Now
 * order owes the product's price less any credit discount, a settlement order
 * owes the auction's own low settlement amount -- and reporting, fulfilment
 * and audit all need to tell them apart without guessing.
 *
 * A Buy Now order may or may not be attached to an auction. Both are Buy Now:
 * the buyer paid the product's price outright. The auction relationship is
 * carried separately in `orders.auction_id`, so the further distinction --
 * "this Buy Now ended a running auction" -- stays available without a third
 * source that would blur what the customer actually bought.
 */
enum OrderSource: string
{
    /** Bought outright at the product's Buy Now price. */
    case BuyNow = 'buy_now';

    /** Won an auction on the highest valid credit bid, and owes settlement. */
    case AuctionWin = 'auction_win';

    public function label(): string
    {
        return match ($this) {
            self::BuyNow => 'Buy Now',
            self::AuctionWin => 'Auction win',
        };
    }

    /**
     * Whether an order of this kind must be attached to an auction.
     *
     * A settlement order is meaningless without one -- it exists because
     * somebody won something. A Buy Now order stands on its own, and only
     * carries an auction when the purchase ended one.
     */
    public function requiresAuction(): bool
    {
        return match ($this) {
            self::AuctionWin => true,
            self::BuyNow => false,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::BuyNow => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::AuctionWin => 'bg-accent-50 text-accent-900 ring-accent-200',
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

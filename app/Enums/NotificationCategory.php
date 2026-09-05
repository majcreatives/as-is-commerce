<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The switch a customer can turn off.
 *
 * Deliberately coarse. A per-type preference screen with fourteen toggles is
 * a way to make people give up rather than a way to give them control, and
 * the brief asks for the simplest thing that is sufficient.
 *
 * `Transactional` appears here so every type has a category, but it is never
 * offered as a switch: anything about money that has moved or an obligation
 * incurred reaches the customer regardless.
 */
enum NotificationCategory: string
{
    /** Money, obligations, orders. Cannot be switched off. */
    case Transactional = 'transactional';

    /** Bid accepted, outbid, auction extended. */
    case Bidding = 'bidding';

    /** How an auction someone took part in turned out. */
    case AuctionResults = 'auction_results';

    /**
     * Progress on a package that is on its way.
     *
     * Switchable because it is detail rather than news. Being told a package
     * has been dispatched or has arrived is not in here -- those are
     * transactional, and a customer expecting a physical thing is always told
     * when it moves and when it lands.
     */
    case DeliveryUpdates = 'delivery_updates';

    public function label(): string
    {
        return match ($this) {
            self::Transactional => 'Payments and orders',
            self::Bidding => 'Bidding activity',
            self::AuctionResults => 'Auction results',
            self::DeliveryUpdates => 'Delivery progress',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Transactional => 'Payments, settlements and anything needing your attention. '
                .'These are always sent.',
            self::Bidding => 'When your bid is accepted, when someone outbids you, and when an '
                .'auction you are bidding in is extended.',
            self::AuctionResults => 'How an auction you took part in ended, including when a '
                .'product is bought outright.',
            self::DeliveryUpdates => 'Step-by-step progress while we prepare and deliver your order. '
                .'You are always told when it is dispatched and when it arrives.',
        };
    }

    /**
     * Whether a customer may switch this category off.
     */
    public function isOptional(): bool
    {
        return $this !== self::Transactional;
    }

    /**
     * The categories offered on the preferences screen.
     *
     * @return list<self>
     */
    public static function optionalCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case): bool => $case->isOptional(),
        ));
    }
}

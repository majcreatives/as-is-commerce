<?php

declare(strict_types=1);

namespace App\Domain\Realtime;

use App\Models\Auction;

/**
 * Everything that may travel on a public auction channel, and nothing else.
 *
 * THIS CLASS EXISTS TO BE A WHITELIST. A broadcast payload is public the
 * moment it is sent: anyone can open a browser console and subscribe to
 * `auction.{id}`. Serializing a model onto that channel would put a bidder's
 * identity on it in one line, because `BidAccepted` carries a `Bid`, which
 * relates to a `User`. So nothing is serialized. Seven fields are named here
 * explicitly, built from the auction record, and a field that is not written
 * below cannot be broadcast by accident.
 *
 * NEVER ADD: a bidder's name, id, phone, email or address; a wallet balance,
 * credit lot or credit transaction; an order number, payment reference or
 * payment detail; the settlement amount a named person owes; the identity of
 * a Buy Now buyer; delivery, refund, referral or notification content. If a
 * screen needs any of that, it reads it over HTTP where authorization applies.
 *
 * CREDITS ARE A COUNT, NEVER MONEY. `highest_bid_credits` is an integer number
 * of credits. It is not a price, it is not GHS, and it must never be passed
 * through a money formatter or written with a currency symbol. The auction's
 * settlement amount and the product's Buy Now price are separate figures in
 * cedis and neither belongs on this channel.
 *
 * SEQUENCE IS THE ORDERING KEY. It comes from the per-auction bid sequence,
 * which is allocated under the auction row lock and is therefore monotonic
 * without a counter of its own. A client holding sequence N discards anything
 * at or below N, which is what makes a duplicated or out-of-order delivery
 * harmless.
 */
final readonly class AuctionStatePayload
{
    private function __construct(
        private int $auctionId,
        private string $status,
        private ?int $highestBidCredits,
        private int $bidCount,
        private ?string $endsAt,
        private int $sequence,
        private int $extendedBySeconds,
    ) {}

    /**
     * Read the public state of an auction as it stands right now.
     *
     * @param  int  $sequence  The bid sequence this state reflects. Zero for a
     *                         change that no bid produced -- a closure, a
     *                         forfeit -- where the auction's own bid count is
     *                         already settled and ordering is decided by the
     *                         terminal status rather than by a new bid.
     */
    public static function for(Auction $auction, int $sequence = 0, int $extendedBySeconds = 0): self
    {
        return new self(
            auctionId: $auction->id,
            // The customer-facing wording, never the engine's own vocabulary.
            status: $auction->status->customerLabel(),
            // A count of credits. Null when nobody has bid: no bids at all is
            // a different state from a bid of nothing.
            highestBidCredits: $auction->highest_bid_credits,
            bidCount: $auction->bid_count,
            // UTC, ISO-8601. The browser renders it in local time; the server
            // decides what it means.
            endsAt: $auction->ends_at?->toIso8601String(),
            sequence: $sequence,
            extendedBySeconds: $extendedBySeconds,
        );
    }

    /**
     * The wire format.
     *
     * @return array{auction_id: int, status: string, highest_bid_credits: int|null, bid_count: int, ends_at: string|null, sequence: int, extended_by_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'auction_id' => $this->auctionId,
            'status' => $this->status,
            'highest_bid_credits' => $this->highestBidCredits,
            'bid_count' => $this->bidCount,
            'ends_at' => $this->endsAt,
            'sequence' => $this->sequence,
            'extended_by_seconds' => $this->extendedBySeconds,
        ];
    }

    /**
     * The only fields this payload may ever carry.
     *
     * Referenced by the privacy tests, so widening the payload means changing
     * this list deliberately rather than by omission.
     *
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        return [
            'auction_id',
            'status',
            'highest_bid_credits',
            'bid_count',
            'ends_at',
            'sequence',
            'extended_by_seconds',
        ];
    }

    public function channelName(): string
    {
        return 'auction.'.$this->auctionId;
    }
}

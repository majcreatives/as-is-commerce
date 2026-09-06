<?php

declare(strict_types=1);

namespace App\Events\Broadcast;

use App\Domain\Realtime\AuctionStatePayload;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One public auction state change, on its way to a browser.
 *
 * NOT A DOMAIN EVENT. Nothing in the application listens to it, nothing
 * decides anything from it, and deleting the whole real-time layer would leave
 * every business rule intact. It is the wire format and nothing more.
 *
 * The four domain events -- `BidAccepted`, `AuctionClosed`,
 * `AuctionSoldViaBuyNow`, `AuctionForfeited` -- stay exactly as they were,
 * carrying their models to the notification and referral subscribers that need
 * them. `AuctionBroadcastSubscriber` listens to those, reduces each to the
 * public whitelist in {@see AuctionStatePayload}, and hands the result here.
 * That is why this class holds an array rather than a model: there is no
 * relationship to follow off it and no way for a private field to arrive by
 * accident.
 *
 * BROADCASTS SYNCHRONOUSLY, ON PURPOSE. `ShouldBroadcastNow` rather than
 * `ShouldBroadcast`, because the queued variant would push a job per bid onto
 * the database queue -- and the current deployment runs cron rather than a
 * worker, so those jobs would accumulate and never be delivered. Broadcasting
 * inline costs one short HTTP call to Reverb, after the bid has already
 * committed, and the subscriber wraps it so a failure cannot reach the bidder.
 *
 * When the broadcaster is `null`, which is the production default today,
 * nothing here runs at all.
 */
final class AuctionStateBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  string  $name  The client-facing event name, e.g. `bid.accepted`.
     */
    public function __construct(
        private readonly AuctionStatePayload $payload,
        private readonly string $name,
    ) {}

    public function broadcastOn(): Channel
    {
        // Public. Everything in the payload is already on the page for anyone
        // who opens it, so a private channel would add an authorization round
        // trip that protects nothing. What keeps this safe is the payload
        // whitelist, not the channel type.
        return new Channel($this->payload->channelName());
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload->toArray();
    }
}

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Realtime\AuctionStatePayload;
use App\Events\AuctionClosed;
use App\Events\AuctionForfeited;
use App\Events\AuctionSoldViaBuyNow;
use App\Events\BidAccepted;
use App\Events\Broadcast\AuctionStateBroadcast;
use App\Models\Auction;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The auction's public state, on its way to whoever is watching.
 *
 * WHY A SUBSCRIBER RATHER THAN `ShouldBroadcast` ON THE DOMAIN EVENTS. Making
 * `BidAccepted` broadcastable hands delivery to Laravel's dispatcher, and a
 * broadcaster that throws then throws through `PlaceBid::handle()` -- giving a
 * bidder a 500 for a bid whose credits were consumed and whose row is
 * committed. With a `sync` queue that is not hypothetical; it is what happens.
 *
 * So the transport sits behind the same guard the notification layer uses: it
 * listens to events that have already committed, and it cannot throw into
 * anything. That is also why no file under `app/Domain/Auction/` needed to
 * change for any of this. A bid does not know it is being broadcast.
 *
 * IT DECIDES NOTHING. It reads state the domain already settled and reduces it
 * to a public whitelist. If this class were deleted, every auction would still
 * open, take bids, close, pick the same winner and settle for the same amount.
 *
 * AFTER THE COMMIT, ALWAYS. Every event it subscribes to is dispatched outside
 * its transaction -- `PlaceBid` and `CloseAuction` both dispatch after
 * `DB::transaction()` returns. Broadcasting a bid that later rolled back would
 * show every watcher a bid that does not exist.
 */
class AuctionBroadcastSubscriber
{
    /**
     * Client-facing event names. Deliberately short and stable: they are part
     * of the contract with the browser, and renaming one silently breaks every
     * open page.
     */
    public const BID_ACCEPTED = 'bid.accepted';

    public const AUCTION_CLOSED = 'auction.closed';

    public const AUCTION_SOLD = 'auction.sold';

    public const AUCTION_FORFEITED = 'auction.forfeited';

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
        ];
    }

    public function onBidAccepted(BidAccepted $event): void
    {
        $auction = $event->bid->auction;

        if ($auction === null) {
            return;
        }

        $this->send(
            // Re-read so the projection reflects the bid that just landed:
            // `rebuild()` wrote it inside the transaction, and the instance
            // hanging off the bid may predate that.
            $auction->fresh() ?? $auction,
            self::BID_ACCEPTED,
            // The per-auction sequence, allocated under the auction row lock.
            // This is the ordering key the client uses to discard duplicates
            // and late arrivals.
            sequence: $event->bid->sequence,
            extendedBySeconds: $event->extendedBySeconds,
        );
    }

    public function onAuctionClosed(AuctionClosed $event): void
    {
        // No new bid, so no new sequence. A terminal status is its own
        // ordering: the client accepts it regardless, because an auction that
        // has ended cannot be superseded by an earlier bid.
        $this->send($event->auction, self::AUCTION_CLOSED);
    }

    public function onSoldViaBuyNow(AuctionSoldViaBuyNow $event): void
    {
        // The buyer is NOT carried. Participants are told the product was
        // bought, never who bought it.
        $this->send($event->auction, self::AUCTION_SOLD);
    }

    public function onAuctionForfeited(AuctionForfeited $event): void
    {
        $this->send($event->auction, self::AUCTION_FORFEITED);
    }

    /**
     * Put one state change on the wire, and never let it come back.
     *
     * Wrapped exactly like the notification subscriber, for the same reason: a
     * failure to describe an event must never look like a failure to do it.
     * An unreachable Reverb, a refused connection, a timeout, a
     * misconfiguration -- all of them end here, in a log line.
     */
    private function send(Auction $auction, string $name, int $sequence = 0, int $extendedBySeconds = 0): void
    {
        try {
            AuctionStateBroadcast::dispatch(
                AuctionStatePayload::for($auction, $sequence, $extendedBySeconds),
                $name,
            );
        } catch (Throwable $e) {
            Log::warning('Auction broadcast failed', [
                'operation' => 'auction.broadcast_failed',
                'event' => $name,
                'auction_id' => $auction->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

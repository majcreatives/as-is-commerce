<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a single auction sits in its lifecycle.
 *
 * The ordinary path:
 *
 *     Draft -> Scheduled -> Live -> Closing -> PendingSettlement -> Settled
 *
 * Closing is optional. An auction whose ruleset has no closing window goes
 * from Live straight to PendingSettlement, because the window exists only to
 * make late-bid extension possible.
 *
 * THE WINNER RULE IS NOT A STATE. Nothing here records who is leading. The
 * winner is the highest valid credit bid, resolved from the bid records at
 * closure -- so a status change can never decide an auction, and no state
 * could be read as "the last bidder is winning".
 *
 * BUY NOW IS DISTINCT. A successful Buy Now moves the auction to Settled, but
 * the reason is recorded separately in {@see AuctionClosureReason}, the buyer
 * is recorded in `buy_now_user_id` rather than `winner_user_id`, and
 * `winning_bid_id` stays null. An auction ended by Buy Now is therefore never
 * mistakable for a normal highest-bid settlement, in either direction.
 */
enum AuctionStatus: string
{
    /** Being configured. Holds no inventory and is not public. */
    case Draft = 'draft';

    /** Published with a start time in the future. Holds a reserved unit. */
    case Scheduled = 'scheduled';

    /** Open. Accepting bids and, if the rules allow, Buy Now. */
    case Live = 'live';

    /**
     * Inside the closing window, where a late bid may extend the clock.
     *
     * Still fully open: bids and Buy Now are accepted exactly as in Live.
     * The distinction is informational, so the interface and the audit trail
     * can show that the auction is about to end.
     */
    case Closing = 'closing';

    /**
     * Closed with a winner, awaiting the winner's settlement payment.
     *
     * The unit stays reserved: it is spoken for but not yet sold.
     */
    case PendingSettlement = 'pending_settlement';

    /** The product changed hands, by settlement or by Buy Now. Terminal. */
    case Settled = 'settled';

    /**
     * Closed with no bids at all.
     *
     * A separate state rather than Cancelled, which would imply someone
     * intervened, or Settled, which would imply a sale. Nobody bid, and the
     * record should say so plainly.
     */
    case Unsold = 'unsold';

    /** Stopped by an administrator before it could close. */
    case Cancelled = 'cancelled';

    /** The winner did not settle within the deadline the rules allowed. */
    case Forfeited = 'forfeited';

    /** Superseded by a fresh auction created for the same product. Terminal. */
    case Relisted = 'relisted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Scheduled => 'Scheduled',
            self::Live => 'Live',
            self::Closing => 'Closing soon',
            self::PendingSettlement => 'Awaiting settlement',
            self::Settled => 'Settled',
            self::Unsold => 'Closed without bids',
            self::Cancelled => 'Cancelled',
            self::Forfeited => 'Forfeited',
            self::Relisted => 'Relisted',
        };
    }

    /**
     * What a customer is told this means.
     *
     * Plainer than the internal label, and never less true. "Awaiting
     * settlement" is engine vocabulary; "won, settlement required" is what
     * actually happened and what the winner has to do about it.
     *
     * `Settled` deliberately says only that the auction was won. Whether it
     * was won by a bidder or ended by somebody buying outright is a fact about
     * the auction rather than about its status, so the badge component decides
     * that from the auction itself.
     */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Draft => 'Not yet published',
            self::Scheduled => 'Starts soon',
            self::Live => 'Live now',
            self::Closing => 'Closing',
            self::PendingSettlement => 'Won — settlement required',
            self::Settled => 'Auction won',
            self::Unsold => 'Ended with no bids',
            self::Cancelled => 'Auction cancelled',
            self::Forfeited => 'Auction forfeited',
            self::Relisted => 'Relisted',
        };
    }

    /**
     * Whether a bid may be placed right now.
     *
     * Status alone is not sufficient -- the clock is checked too -- but no
     * bid may be accepted in any other state.
     */
    public function acceptsBids(): bool
    {
        return $this === self::Live || $this === self::Closing;
    }

    /**
     * Whether a Buy Now purchase may complete right now.
     *
     * The same window as bidding, which is what makes the race between them
     * real and why both serialize on the auction row.
     */
    public function acceptsBuyNow(): bool
    {
        return $this === self::Live || $this === self::Closing;
    }

    /**
     * Whether the auction is open to the public and running.
     */
    public function isOpen(): bool
    {
        return $this === self::Live || $this === self::Closing;
    }

    /**
     * Whether the auction currently holds a reserved unit of stock.
     *
     * Publishing reserves one unit; closing either sells it or gives it back.
     * This is the single definition of that, so the lifecycle and any audit
     * of inventory read the same rule.
     */
    public function holdsInventory(): bool
    {
        return match ($this) {
            self::Scheduled, self::Live, self::Closing, self::PendingSettlement => true,
            self::Draft, self::Settled, self::Unsold, self::Cancelled, self::Forfeited, self::Relisted => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether an administrator may still edit the auction's configuration.
     *
     * Only a draft. Once published, the snapshot is frozen -- in the model,
     * and again by a database trigger.
     */
    public function isConfigurable(): bool
    {
        return $this === self::Draft;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * The only legal moves. Anything else is a bug.
     *
     * A state machine that permits arbitrary moves is how an auction ends up
     * settled without a winner, or reopened after it closed.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Live, self::Cancelled],
            self::Scheduled => [self::Live, self::Cancelled],
            // Settled is reachable directly from an open state only by a
            // successful Buy Now, which ends the auction on the spot.
            self::Live => [
                self::Closing, self::PendingSettlement, self::Unsold, self::Settled, self::Cancelled,
            ],
            self::Closing => [
                self::PendingSettlement, self::Unsold, self::Settled, self::Cancelled,
            ],
            self::PendingSettlement => [self::Settled, self::Forfeited, self::Cancelled],
            // Terminal: the product changed hands. Reversing a sale is a
            // refund, which is its own flow with its own records.
            self::Settled => [],
            self::Unsold, self::Cancelled, self::Forfeited => [self::Relisted],
            self::Relisted => [],
        };
    }

    /**
     * The statuses the public auction listing may show.
     *
     * @return list<self>
     */
    public static function publiclyVisibleCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $status): bool => $status->isPubliclyVisible(),
        ));
    }

    /**
     * Whether the public may see an auction in this state.
     *
     * Drafts are hidden: an unpublished auction is not a promise to anyone.
     * Closed auctions stay visible, because a bidder who spent credits is
     * entitled to see how it ended.
     */
    public function isPubliclyVisible(): bool
    {
        return match ($this) {
            self::Draft => false,
            default => true,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Live => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Closing => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::Scheduled => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::PendingSettlement => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Settled => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Draft, self::Unsold, self::Relisted => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Cancelled, self::Forfeited => 'bg-red-50 text-red-800 ring-red-200',
        };
    }
}

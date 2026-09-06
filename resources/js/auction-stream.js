/*
 * Live auction updates, when a broadcast transport is available.
 *
 * WHAT THIS IS FOR. The auction room already works without a line of this: it
 * polls, and the server computes every figure on it. This makes a change show
 * up in under a second instead of within a poll interval. That is the whole of
 * its job.
 *
 * WHAT IT IS NOT FOR. It decides nothing. It never computes a price, a
 * discount, a winner, a minimum bid or whether an auction is open. Those are
 * settled in MySQL under row locks, and a browser that disagreed with the
 * server would simply be wrong until its next poll corrected it.
 *
 * THE PAYLOAD IS PRESENTATION, NOT COMMAND. A message says "here is the public
 * state now", never "add one bid". That distinction is what makes a duplicated
 * delivery harmless: applying the same state twice leaves the same state.
 */

/**
 * Event names that end an auction. Nothing supersedes one of these.
 */
export const TERMINAL_EVENTS = ['auction.closed', 'auction.sold', 'auction.forfeited'];

/**
 * The client's memory of what it has already seen.
 *
 * Deliberately tiny: a sequence number and whether the auction has ended. It
 * holds no bid list, no running total and no auction facts, because
 * reconstructing state from a stream is exactly how a browser ends up
 * disagreeing with the database.
 */
export function createStreamState() {
    return { lastSequence: 0, terminal: false };
}

/**
 * Whether an arriving message should be applied.
 *
 * Four rules, in order:
 *
 *   1. Once an auction has ended, nothing more is applied. A late bid message
 *      cannot reopen it, and a second closure cannot change it.
 *   2. A terminal message is always applied. It carries no bid sequence
 *      because no bid produced it, and an ended auction is its own ordering.
 *   3. A message at or below the sequence already seen is discarded. This is
 *      the duplicate case and the out-of-order case at once: 105 then 103
 *      leaves 105, and 105 then 105 leaves 105 having done nothing twice.
 *   4. Anything strictly newer is applied, and becomes the new high-water
 *      mark.
 *
 * Mutates and returns whether to render, rather than returning new state,
 * because there is exactly one of these per page and a caller that forgot to
 * reassign would silently reprocess everything.
 */
export function shouldApply(state, event) {
    if (state.terminal) {
        return false;
    }

    if (TERMINAL_EVENTS.includes(event.name)) {
        state.terminal = true;

        return true;
    }

    const sequence = Number(event.payload?.sequence ?? 0);

    if (!Number.isFinite(sequence) || sequence <= state.lastSequence) {
        return false;
    }

    state.lastSequence = sequence;

    return true;
}

/**
 * Paint the public figures a payload carries.
 *
 * Only the fields the server put on the wire, and only into elements that
 * exist. Everything viewer-specific -- the Buy Now discount, whether you are
 * leading, your own bids, the settlement link -- is deliberately absent here:
 * it is not on the channel, and it comes back on the next authoritative read.
 *
 * Livewire's next poll re-renders these same elements from the database. When
 * it does, it overwrites whatever this wrote, which is the correct direction
 * for the disagreement to be resolved.
 */
export function applyPayload(root, payload) {
    const set = (selector, value) => {
        const el = root.querySelector(selector);

        if (el && value !== null && value !== undefined) {
            el.textContent = value;
        }
    };

    // A count of credits. Never money, never a currency symbol -- the server
    // sends an integer and this writes an integer.
    if (payload.highest_bid_credits !== null && payload.highest_bid_credits !== undefined) {
        set('[data-auction-highest-credits]', Number(payload.highest_bid_credits).toLocaleString());
    }

    if (payload.bid_count !== null && payload.bid_count !== undefined) {
        const count = Number(payload.bid_count);

        set('[data-auction-bid-count]', `${count.toLocaleString()} ${count === 1 ? 'bid' : 'bids'} placed.`);
    }

    set('[data-auction-status]', payload.status);
}

/**
 * Subscribe one auction room to its public channel.
 *
 * @param {object} options
 * @param {object} options.echo      A Laravel Echo instance, or null when no
 *                                   transport is configured.
 * @param {HTMLElement} options.root The auction room element.
 * @param {number} options.auctionId
 * @param {Function} options.refresh Ask the server for authoritative state.
 * @returns {Function|null} An unsubscribe function, or null when inactive.
 */
export function listenToAuction({ echo, root, auctionId, refresh }) {
    if (!echo || !root || !auctionId) {
        // No transport configured. The page keeps polling, which is how it has
        // always worked and remains the only mechanism production relies on.
        return null;
    }

    const state = createStreamState();
    const channel = echo.channel(`auction.${auctionId}`);

    const handle = (name) => (payload) => {
        if (!shouldApply(state, { name, payload })) {
            return;
        }

        applyPayload(root, payload);

        if (TERMINAL_EVENTS.includes(name)) {
            // An ended auction changes far more than the three public figures
            // above -- a settlement link appears for the winner, a losing
            // bidder is owed a straight answer, the bid form goes. None of
            // that is on a public channel and none of it should be. Ask the
            // server, which knows who is looking.
            refresh();
        }
    };

    channel.listen('.bid.accepted', handle('bid.accepted'));
    channel.listen('.auction.closed', handle('auction.closed'));
    channel.listen('.auction.sold', handle('auction.sold'));
    channel.listen('.auction.forfeited', handle('auction.forfeited'));

    // RECONNECTION: RE-READ, NEVER REPLAY.
    //
    // A dropped socket means messages were missed, and there is no way to know
    // how many. Asking the server for current state answers that completely and
    // in one round trip; replaying a buffer would answer it partially and
    // invite the browser to reconstruct auction facts, which is the one thing
    // it must never do.
    //
    // The sequence high-water mark is deliberately kept: whatever the refresh
    // returns is at least as new as anything missed, so an old message still
    // in flight is discarded on arrival.
    const connector = echo.connector?.pusher;

    if (connector?.connection?.bind) {
        connector.connection.bind('connected', () => {
            if (state.reconnecting) {
                state.reconnecting = false;
                refresh();
            }
        });

        connector.connection.bind('unavailable', () => {
            state.reconnecting = true;
        });

        connector.connection.bind('disconnected', () => {
            state.reconnecting = true;
        });
    }

    return () => echo.leave(`auction.${auctionId}`);
}

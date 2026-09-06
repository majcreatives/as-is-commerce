/*
 * The client's ordering and idempotency rules.
 *
 * Run with:  npm run test:js
 *
 * Node's own test runner, deliberately: proving these four rules needs no
 * framework, and adding one to a project that is Blade, Livewire, Tailwind and
 * Vite would be a dependency earning its keep once.
 *
 * These are the rules that stop a browser disagreeing with the database when
 * the network misbehaves. A socket delivers at least once and in no guaranteed
 * order, so duplicates and late arrivals are normal traffic rather than
 * failures, and the client has to be uninteresting about both.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { createStreamState, shouldApply, applyPayload, TERMINAL_EVENTS } from './auction-stream.js';

const bid = (sequence) => ({ name: 'bid.accepted', payload: { sequence } });

test('applies a first bid', () => {
    const state = createStreamState();

    assert.equal(shouldApply(state, bid(1)), true);
    assert.equal(state.lastSequence, 1);
});

test('applies strictly newer bids in order', () => {
    const state = createStreamState();

    assert.equal(shouldApply(state, bid(100)), true);
    assert.equal(shouldApply(state, bid(101)), true);
    assert.equal(shouldApply(state, bid(102)), true);
    assert.equal(state.lastSequence, 102);
});

test('discards a duplicate delivery', () => {
    const state = createStreamState();

    assert.equal(shouldApply(state, bid(105)), true);

    // The same message again. Applying it would be harmless -- the payload is
    // state, not an increment -- but doing nothing is cheaper and proves the
    // client is not counting.
    assert.equal(shouldApply(state, bid(105)), false);
    assert.equal(state.lastSequence, 105);
});

test('discards an out-of-order delivery', () => {
    const state = createStreamState();

    assert.equal(shouldApply(state, bid(105)), true);
    assert.equal(shouldApply(state, bid(103)), false);

    // The newer state stands. A late message cannot walk the page backwards.
    assert.equal(state.lastSequence, 105);
});

test('ends on 102 for the specified 100, 102, 101 sequence', () => {
    const state = createStreamState();

    assert.equal(shouldApply(state, bid(100)), true);
    assert.equal(shouldApply(state, bid(102)), true);
    assert.equal(shouldApply(state, bid(101)), false);

    assert.equal(state.lastSequence, 102);
});

test('applies a terminal event whatever its sequence', () => {
    for (const name of TERMINAL_EVENTS) {
        const state = createStreamState();

        shouldApply(state, bid(50));

        // No bid produced it, so it carries no sequence of its own.
        assert.equal(shouldApply(state, { name, payload: { sequence: 0 } }), true);
        assert.equal(state.terminal, true);
    }
});

test('lets nothing supersede an ended auction', () => {
    const state = createStreamState();

    shouldApply(state, { name: 'auction.closed', payload: { sequence: 0 } });

    // A bid message still in flight when the auction closed must not reopen it.
    assert.equal(shouldApply(state, bid(9999)), false);

    // Nor may a second closure notice be processed twice.
    assert.equal(shouldApply(state, { name: 'auction.sold', payload: { sequence: 0 } }), false);
});

test('discards a payload with no usable sequence', () => {
    const state = createStreamState();

    assert.equal(shouldApply(state, { name: 'bid.accepted', payload: {} }), false);
    assert.equal(shouldApply(state, { name: 'bid.accepted', payload: { sequence: 'abc' } }), false);
    assert.equal(state.lastSequence, 0);
});

/*
 * Rendering. A tiny DOM stand-in rather than a browser: what is being checked
 * is which fields are written and how they are formatted, not layout.
 */
function fakeRoot() {
    const nodes = {
        '[data-auction-highest-credits]': { textContent: '' },
        '[data-auction-bid-count]': { textContent: '' },
        '[data-auction-status]': { textContent: '' },
    };

    return { nodes, querySelector: (selector) => nodes[selector] ?? null };
}

test('writes credits as a count and never as money', () => {
    const root = fakeRoot();

    applyPayload(root, { highest_bid_credits: 1500, bid_count: 4, status: 'Live now' });

    const rendered = root.nodes['[data-auction-highest-credits]'].textContent;

    assert.equal(rendered, '1,500');

    // Credits are a count. A currency symbol here would state that 1,500
    // credits are GH1,500, which is the one thing the platform's economics
    // depend on nobody believing.
    assert.ok(!rendered.includes('GH'));
    assert.ok(!rendered.includes('₵'));
    assert.ok(!rendered.includes('$'));
});

test('writes the bid count and the customer-facing status', () => {
    const root = fakeRoot();

    applyPayload(root, { highest_bid_credits: 20, bid_count: 1, status: 'Closing' });

    assert.equal(root.nodes['[data-auction-bid-count]'].textContent, '1 bid placed.');
    assert.equal(root.nodes['[data-auction-status]'].textContent, 'Closing');

    applyPayload(root, { highest_bid_credits: 20, bid_count: 3, status: 'Closing' });

    assert.equal(root.nodes['[data-auction-bid-count]'].textContent, '3 bids placed.');
});

test('leaves a field alone when the payload omits it', () => {
    const root = fakeRoot();

    applyPayload(root, { highest_bid_credits: 500, bid_count: 2, status: 'Live now' });
    applyPayload(root, { highest_bid_credits: null, bid_count: 2, status: 'Live now' });

    // No bids is a state the server renders in words; the stream does not
    // overwrite it with a zero.
    assert.equal(root.nodes['[data-auction-highest-credits]'].textContent, '500');
});

test('applying the same payload twice leaves one state', () => {
    const root = fakeRoot();
    const payload = { highest_bid_credits: 300, bid_count: 2, status: 'Live now' };

    applyPayload(root, payload);
    const once = { ...root.nodes['[data-auction-bid-count]'] };

    applyPayload(root, payload);

    // State, not commands. Twice is the same as once.
    assert.equal(root.nodes['[data-auction-bid-count]'].textContent, once.textContent);
});

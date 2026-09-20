/*
 * The gallery's index arithmetic and gesture threshold.
 *
 * Run with:  npm run test:js
 *
 * These are the parts that can be wrong without looking wrong: an off-by-one
 * at either end of a gallery, and a swipe handler that steals a scroll. The
 * rest of the viewer is Alpine binding attributes to markup the server wrote,
 * which a unit test would only restate.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    nextIndex,
    previousIndex,
    swipeDirection,
    SWIPE_THRESHOLD_PX,
} from './product-gallery.js';

// ------------------------------------------------------------- Moving about

test('advances through a gallery', () => {
    assert.equal(nextIndex(0, 3), 1);
    assert.equal(nextIndex(1, 3), 2);
});

test('wraps to the first image past the end', () => {
    assert.equal(nextIndex(2, 3), 0);
});

test('wraps to the last image before the start', () => {
    assert.equal(previousIndex(0, 3), 2);
});

test('goes back through a gallery', () => {
    assert.equal(previousIndex(2, 3), 1);
    assert.equal(previousIndex(1, 3), 0);
});

test('stays put in a gallery of one', () => {
    assert.equal(nextIndex(0, 1), 0);
    assert.equal(previousIndex(0, 1), 0);
});

test('stays put with no images at all', () => {
    // A product with no image still renders a page; nothing here may divide
    // by zero or return an index into an empty gallery.
    assert.equal(nextIndex(0, 0), 0);
    assert.equal(previousIndex(0, 0), 0);
});

// ------------------------------------------------------------------ Gestures

test('reads a leftward drag as the next image', () => {
    const direction = swipeDirection({ x: 300, y: 100 }, { x: 200, y: 105 });

    assert.equal(direction, 'next');
});

test('reads a rightward drag as the previous image', () => {
    const direction = swipeDirection({ x: 200, y: 100 }, { x: 300, y: 95 });

    assert.equal(direction, 'previous');
});

test('ignores a tap', () => {
    const direction = swipeDirection({ x: 200, y: 100 }, { x: 203, y: 101 });

    assert.equal(direction, null);
});

test('ignores a drag that is shorter than the threshold', () => {
    const shortOfIt = SWIPE_THRESHOLD_PX - 1;
    const direction = swipeDirection({ x: 200, y: 100 }, { x: 200 - shortOfIt, y: 100 });

    assert.equal(direction, null);
});

test('leaves a vertical scroll alone', () => {
    // Travelled further down the page than across it: somebody scrolling,
    // not somebody changing image. Stealing this makes a product page
    // unreadable on a phone.
    const direction = swipeDirection({ x: 200, y: 100 }, { x: 150, y: 400 });

    assert.equal(direction, null);
});

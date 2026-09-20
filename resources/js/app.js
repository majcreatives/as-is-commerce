/*
 * The application's only JavaScript.
 *
 * It attaches live auction updates where a broadcast transport is configured,
 * and does nothing at all where one is not. Every page renders and every
 * action works with this file absent, which is the property that lets the
 * current production deployment run without a WebSocket server.
 *
 * The product gallery is registered here for the same reason it is written to
 * degrade: with this file missing, a product page still shows the product.
 */

import { resolveEcho } from './echo.js';
import { listenToAuction } from './auction-stream.js';
import { productGallery } from './product-gallery.js';

// Alpine ships inside Livewire, and components have to be registered before it
// starts. Nothing on a page depends on this having happened: the markup the
// gallery enhances is rendered by the server and readable on its own.
document.addEventListener('alpine:init', () => {
    window.Alpine?.data('productGallery', productGallery);
});

const subscriptions = new WeakMap();

function attachAuctionRooms() {
    document.querySelectorAll('[data-auction-room]').forEach((root) => {
        if (subscriptions.has(root)) {
            return;
        }

        const auctionId = Number(root.dataset.auctionRoom);

        const unsubscribe = listenToAuction({
            echo: resolveEcho(),
            root,
            auctionId,
            // The authoritative re-read. Livewire re-renders the component
            // from the database, which is where every figure on the page comes
            // from in the first place.
            refresh: () => window.Livewire?.find(root.dataset.auctionComponent)?.$refresh(),
        });

        if (unsubscribe) {
            subscriptions.set(root, unsubscribe);
        }
    });
}

document.addEventListener('DOMContentLoaded', attachAuctionRooms);

// Livewire replaces the component's DOM on navigation and on some updates, so
// a room can appear after the initial load.
document.addEventListener('livewire:navigated', attachAuctionRooms);

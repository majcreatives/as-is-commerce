/*
 * Laravel Echo, but only when there is something to connect to.
 *
 * The current production deployment broadcasts nothing: the driver is `null`,
 * because Hostinger Premium runs scheduled cron tasks rather than a persistent
 * Reverb server. On that deployment this module resolves to null, no
 * connection is attempted, and the auction room polls exactly as it did
 * before.
 *
 * That is the intended state, not a degraded one. A page that cannot reach a
 * WebSocket must behave identically to a page that never looked for one.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;

/**
 * The Echo instance, or null when no transport is configured.
 *
 * Built lazily and once. Constructing it eagerly would open a socket on every
 * page of the marketplace, including the ones with nothing live on them.
 */
export function resolveEcho() {
    if (echo !== null) {
        return echo;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        // Nothing configured. Polling carries the page.
        return null;
    }

    try {
        window.Pusher = Pusher;

        echo = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });

        return echo;
    } catch (error) {
        // A misconfigured transport must not take the page down with it. The
        // auction still works; it just updates on its poll interval.
        console.warn('Live auction updates unavailable; falling back to polling.', error);

        return null;
    }
}

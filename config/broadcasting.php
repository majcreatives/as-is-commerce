<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default broadcaster
    |--------------------------------------------------------------------------
    |
    | `null` BY DEFAULT, AND THAT IS THE PRODUCTION SETTING TODAY. The current
    | deployment target is Hostinger Premium, which runs scheduled cron tasks
    | rather than persistent processes, so there is no Reverb server to
    | broadcast to. With `null` the application performs no broadcast work at
    | all -- no connection attempt, no queued job, no log line -- and the
    | auction room stays exactly as it was: Livewire polling over HTTP, with
    | MySQL authoritative.
    |
    | Switching this to `reverb` on infrastructure that can run the server
    | turns the real-time layer on. Nothing else has to change, because nothing
    | in the commerce path knows or cares whether it is set.
    |
    | A REVERB OR REDIS OUTAGE IS NOT A COMMERCE OUTAGE. Bids, closures, Buy
    | Now, settlement, payments and inventory are decided in MySQL under row
    | locks and are dispatched to this layer only after they have committed.
    | The worst a broken transport can do is leave a page refreshing on its
    | poll interval instead of updating instantly.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast connections
    |--------------------------------------------------------------------------
    */

    'connections' => [

        /*
         * Reverb speaks the Pusher protocol, so the application broadcasts
         * through `pusher/pusher-php-server` and Reverb's own `laravel/reverb`
         * package is the *server*. Stage 32.23 installed it for the
         * production-shaped staging VPS, where Supervisor actually runs
         * `php artisan reverb:start` (docs/STAGING_VPS.md). On Hostinger
         * Premium nothing starts it and BROADCAST_CONNECTION=null, so the
         * package rides in the deployable inert.
         */
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Short on purpose. This call happens after a bid has already
                // committed, so waiting on it buys nothing and costs the
                // bidder their response time.
                'timeout' => (int) env('REVERB_TIMEOUT', 5),
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST'),
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
        ],

        /*
         * Development only. Writes each payload to the log, which is a cheap
         * way to read exactly what would go on the wire without running a
         * server. Never production: a line per bid fills a shared-hosting
         * disk, and the payload is public but the log is not the place for it.
         */
        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];

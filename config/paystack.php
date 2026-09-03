<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Paystack
|--------------------------------------------------------------------------
|
| Credentials come from the environment and are never committed. The secret
| key authenticates server-to-server API calls and verifies webhook
| signatures; it must never reach the browser. Only the public key may be
| exposed to a client.
|
| Tests do not need real credentials: the HTTP client is faked, and the
| signature verifier works against whatever secret is configured.
|
*/

return [
    'secret_key' => env('PAYSTACK_SECRET_KEY'),

    'public_key' => env('PAYSTACK_PUBLIC_KEY'),

    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    'currency' => env('PAYSTACK_CURRENCY', 'GHS'),

    /*
     | Seconds to wait on an API call. Verification runs inside the webhook
     | request, so this is kept short: a provider that is slow to answer
     | should not hold the webhook open long enough to trigger a retry.
     */
    'timeout' => (int) env('PAYSTACK_TIMEOUT', 15),

    /*
     | Where Paystack returns the customer's browser after payment. The
     | callback is for user experience only and is never treated as proof of
     | payment.
     */
    'callback_route' => 'credits.callback',
];

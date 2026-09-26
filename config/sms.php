<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SMS
|--------------------------------------------------------------------------
|
| Credentials for the outbound SMS gateway, used by the password-recovery
| code channel.
|
| The provider is a delivery pipe and nothing more. The code is generated,
| hashed, expired and consumed entirely on this server, so no third party ever
| holds a credential. That is why the plain "send this text" API is used and
| not a provider's hosted OTP product, which would take over the code's
| lifecycle and leave us unable to expire, retry or audit it ourselves.
|
| Credentials are never committed. Tests do not need real ones: the HTTP
| client is faked.
|
*/

return [
    'api_key' => env('SMS_API_KEY'),

    /*
     | The registered sender ID, i.e. the name or number a Ghanaian handset
     | shows the customer when the message arrives. Arkesel rejects requests
     | whose sender is not registered to the account, so an unregistered value
     | surfaces as a delivery failure rather than as a silent no-op.
     */
    'sender_id' => env('SMS_SENDER_ID'),

    'base_url' => env('SMS_BASE_URL', 'https://sms.arkesel.com'),

    /*
     | Seconds to wait. Delivery is synchronous and sits inside the
     | transaction that issues the code, so a slow provider should fail fast
     | and let the customer ask for another code rather than hold a database
     | transaction open.
     */
    'timeout' => (int) env('SMS_TIMEOUT', 10),
];

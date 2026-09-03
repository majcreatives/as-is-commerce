<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Administrator bootstrap
|--------------------------------------------------------------------------
|
| Optional credentials consumed by `php artisan app:create-admin`. Leave every
| value unset to be prompted interactively instead -- that is the preferred
| path, since it keeps the password out of both the environment file and the
| cached configuration.
|
| These values must never be committed, and should be cleared from .env once
| the account has been created.
|
*/

return [
    'name' => env('ADMIN_NAME'),
    'phone' => env('ADMIN_PHONE'),
    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),
];

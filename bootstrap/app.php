<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so it covers the marketplace, the admin, the auth screens and
        // any error page rendered through the web stack. Applied here rather
        // than on a route group because a header that depends on remembering
        // which routes were grouped is a header that eventually gets forgotten
        // on the one route that needed it.
        $middleware->append(SecurityHeaders::class);

        // Paystack cannot present a CSRF token. The webhook is protected by
        // its HMAC signature instead, which is verified before anything is
        // stored or acted on.
        $middleware->validateCsrfTokens(except: [
            'webhooks/paystack',
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'phone.verified' => EnsurePhoneIsVerified::class,
            'noindex' => NoIndex::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('health') || $request->expectsJson(),
        );
    })->create();

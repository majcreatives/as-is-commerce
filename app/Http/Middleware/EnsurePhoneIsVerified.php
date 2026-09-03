<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates actions that must not be performed by an unverified account.
 *
 * Applied to nothing yet -- the actions it protects (buying credits, placing
 * bids) belong to later stages. It exists now so those stages attach a guard
 * rather than invent one.
 */
final class EnsurePhoneIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasVerifiedPhone()) {
            return $next($request);
        }

        return redirect()
            ->route('profile.edit')
            ->with('status', 'Verify your phone number to continue.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness/readiness probe.
 *
 * Reports only whether the application and its database are reachable.
 * Driver names, hostnames, credentials and exception messages are
 * deliberately excluded: this endpoint is unauthenticated.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $databaseIsUp = $this->databaseIsReachable();

        return response()->json(
            [
                'status' => $databaseIsUp ? 'ok' : 'degraded',
                'database' => $databaseIsUp ? 'ok' : 'unavailable',
            ],
            $databaseIsUp ? 200 : 503,
        );
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable $e) {
            // Logged for operators; never returned to the caller.
            report($e);

            return false;
        }
    }
}

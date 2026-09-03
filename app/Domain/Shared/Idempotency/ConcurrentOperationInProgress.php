<?php

declare(strict_types=1);

namespace App\Domain\Shared\Idempotency;

use RuntimeException;

/**
 * Raised when a request arrives carrying a key whose operation is still in
 * flight elsewhere.
 *
 * Deliberately an error rather than a wait. The caller should retry once the
 * first attempt has settled; guessing at its outcome, or proceeding alongside
 * it, is how duplicate financial effects happen.
 */
final class ConcurrentOperationInProgress extends RuntimeException
{
    public static function for(string $operation, string $key): self
    {
        return new self(
            "Operation [{$operation}] with key [{$key}] is already in progress. "
            .'Retry once it has completed.'
        );
    }
}

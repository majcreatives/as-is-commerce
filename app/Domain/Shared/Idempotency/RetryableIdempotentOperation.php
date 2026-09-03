<?php

declare(strict_types=1);

namespace App\Domain\Shared\Idempotency;

use RuntimeException;

/**
 * Raised when a key is reused after a previous attempt failed.
 *
 * The failed attempt had no financial effect, so the key has been released
 * and the caller may simply try again.
 */
final class RetryableIdempotentOperation extends RuntimeException {}

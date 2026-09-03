<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of an idempotency claim.
 *
 * A key is claimed before the work begins and marked complete only once the
 * work has succeeded, so a retry can tell the difference between "this already
 * happened" and "this is happening right now".
 */
enum IdempotencyStatus: string
{
    /** Claimed; the operation is in flight. */
    case Pending = 'pending';

    /** The operation succeeded. Its result is stored and replayable. */
    case Completed = 'completed';

    /** The operation failed. The key may be retried. */
    case Failed = 'failed';
}

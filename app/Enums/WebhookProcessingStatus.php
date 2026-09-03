<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happened to a webhook event after it was accepted and stored.
 *
 * The event is persisted before processing, so an event that fails to process
 * is still on record and can be examined or replayed rather than being lost.
 */
enum WebhookProcessingStatus: string
{
    /** Signature verified and stored; not yet processed. */
    case Received = 'received';

    /** Processed successfully. */
    case Processed = 'processed';

    /** Understood but deliberately not acted on, e.g. an event type we do not handle. */
    case Ignored = 'ignored';

    /** Processing failed. The stored payload allows a retry. */
    case Failed = 'failed';

    /** Recognised as a repeat of an event already handled. */
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processed => 'Processed',
            self::Ignored => 'Ignored',
            self::Failed => 'Failed',
            self::Duplicate => 'Duplicate',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Processed => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Received => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::Ignored, self::Duplicate => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Failed => 'bg-red-50 text-red-800 ring-red-200',
        };
    }
}

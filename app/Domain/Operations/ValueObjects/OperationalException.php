<?php

declare(strict_types=1);

namespace App\Domain\Operations\ValueObjects;

use DateTimeInterface;

/**
 * One thing that needs a person to look at it.
 *
 * A description, never a record. Nothing here is stored, nothing is a second
 * source of truth, and resolving one means acting on the underlying thing --
 * paying a refund, packing a package, deciding what is owed -- not marking
 * anything here as done. There is deliberately no "dismiss" and no
 * "acknowledge": an exception disappears when the situation it describes stops
 * being true, and not before.
 *
 * NOTHING SENSITIVE TRAVELS IN ONE. The detail line is written for a member of
 * staff and carries references, amounts and reasons -- never a card number, a
 * provider secret, a webhook signature, an authentication token or a customer's
 * contact details.
 */
final readonly class OperationalException
{
    public function __construct(
        /** Which part of the platform noticed. */
        public string $category,
        /** A stable machine-readable kind, for filtering and counting. */
        public string $type,
        public Severity $severity,
        /** What a member of staff needs to know, in one sentence. */
        public string $detail,
        /** What it is about: an order number, a delivery reference, an id. */
        public ?string $reference = null,
        /** Where to go and look. Null when there is no detail page. */
        public ?string $url = null,
        /** When the underlying thing happened, where that is known. */
        public ?DateTimeInterface $detectedAt = null,
        /**
         * The one safe thing to do next, described rather than performed.
         *
         * Never a control. Every action lives on the page that owns the
         * record, behind the permission that governs it.
         */
        public ?string $nextAction = null,
    ) {}

    public function isCritical(): bool
    {
        return $this->severity === Severity::Critical;
    }
}

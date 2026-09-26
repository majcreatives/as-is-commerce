<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use App\Enums\OtpPurpose;
use DateTimeImmutable;

/**
 * A code, plus where it may be delivered.
 *
 * The channel picks whichever destination it actually sends to: the mail
 * channel reads `email`, the SMS channel reads `phone`. The object is
 * deliberately read-only and carries only the code, its expiry and the
 * delivery targets -- never the user's password, never tokens, and nothing a
 * channel could leak beyond what it was given.
 */
final class OtpDelivery
{
    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        /**
         * When the code stops being honoured, when the caller knows it.
         *
         * Passed in rather than recomputed so a message can quote the same
         * window the server enforces. Optional, and a channel must not invent
         * a window of its own when it is absent -- a message promising ten
         * minutes for a code that lives five is a lie told to the customer.
         */
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {}

    /**
     * The address this delivery actually targets, if any.
     */
    public function destinationOrNull(): ?string
    {
        return $this->email ?? $this->phone;
    }
}

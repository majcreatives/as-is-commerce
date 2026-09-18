<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

use App\Enums\OtpPurpose;

/**
 * A code, plus where it may be delivered.
 *
 * The channel picks whichever destination it actually sends to: the mail
 * channel reads `email`, a future SMS channel reads `phone`. The object is
 * deliberately read-only and carries only the code and the delivery targets --
 * never the user's password, never tokens, and nothing a channel could leak
 * beyond what it was given.
 */
final class OtpDelivery
{
    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
    ) {}

    /**
     * The address this delivery actually targets, if any.
     */
    public function destinationOrNull(): ?string
    {
        return $this->email ?? $this->phone;
    }
}

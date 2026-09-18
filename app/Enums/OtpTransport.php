<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A transport that can carry a one-time code.
 *
 * Recorded with every issued code so verification knows which address the
 * code reached, and so an SMS provider can later be added as a second
 * transport rather than a second system.
 */
enum OtpTransport: string
{
    case Sms = 'sms';
    case Mail = 'mail';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Mail => 'Email',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a one-time code may be used for.
 *
 * Email verification and password reset are delivered today through the mail
 * transport. Phone verification is deliberately absent from the flows until
 * an SMS transport exists; its value is established here so the future
 * transport wires into the same machinery rather than inventing its own.
 */
enum OtpPurpose: string
{
    case EmailVerify = 'email_verify';
    case PhoneVerify = 'phone_verify';
    case PasswordReset = 'password_reset';

    public function label(): string
    {
        return match ($this) {
            self::EmailVerify => 'Verify your email address',
            self::PhoneVerify => 'Verify your phone number',
            self::PasswordReset => 'Reset your password',
        };
    }
}

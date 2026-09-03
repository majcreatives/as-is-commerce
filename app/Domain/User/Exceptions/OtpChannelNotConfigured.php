<?php

declare(strict_types=1);

namespace App\Domain\User\Exceptions;

use App\Domain\User\Contracts\OtpChannel;
use RuntimeException;

final class OtpChannelNotConfigured extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No OTP channel is configured. An SMS provider must be bound to '
            .OtpChannel::class.' before phone '
            .'verification can be used.'
        );
    }
}

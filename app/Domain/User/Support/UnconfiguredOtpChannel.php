<?php

declare(strict_types=1);

namespace App\Domain\User\Support;

use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Exceptions\OtpChannelNotConfigured;

/**
 * Default binding for OtpChannel.
 *
 * Fails loudly rather than silently pretending a code was delivered. A
 * verification flow that appears to work without an SMS provider would be a
 * fake success, which this project does not permit.
 */
final class UnconfiguredOtpChannel implements OtpChannel
{
    public function send(string $e164, string $code): void
    {
        throw OtpChannelNotConfigured::make();
    }
}

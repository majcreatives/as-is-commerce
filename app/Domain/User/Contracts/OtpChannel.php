<?php

declare(strict_types=1);

namespace App\Domain\User\Contracts;

use App\Domain\User\Exceptions\OtpChannelNotConfigured;

/**
 * Delivery channel for one-time verification codes.
 *
 * No provider is integrated yet. This contract exists so phone verification
 * can be required at the points that will need it (credit purchase, bidding)
 * and an SMS provider dropped in later without reworking call sites.
 */
interface OtpChannel
{
    /**
     * Deliver a one-time code to the given E.164 number.
     *
     * @throws OtpChannelNotConfigured
     */
    public function send(string $e164, string $code): void;
}

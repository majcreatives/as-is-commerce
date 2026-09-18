<?php

declare(strict_types=1);

namespace App\Domain\User\Contracts;

use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\ValueObjects\OtpDelivery;

/**
 * Delivery channel for one-time verification codes.
 *
 * Transport-agnostic: the mail channel reads the delivery's `email` address
 * today, and a future SMS channel reads its `phone` number without any caller
 * learning which channel carried the code. A channel fails loudly
 * (OtpDeliveryException) rather than pretending the code was delivered --
 * verification must never appear to work while no code actually arrived.
 */
interface OtpChannel
{
    /**
     * Deliver a one-time code through this transport.
     *
     * @throws OtpDeliveryException when the code cannot be delivered
     */
    public function send(OtpDelivery $delivery): void;
}

<?php

declare(strict_types=1);

namespace App\Domain\Orders\Exceptions;

use App\Enums\OrderStatus;
use DomainException;

/**
 * A lifecycle move the order state machine does not permit.
 *
 * The one that matters most is anything reaching Paid other than from
 * PendingPayment through a verified payment. An order state machine that
 * permits arbitrary moves is how an order gets marked paid without money.
 */
final class InvalidOrderTransition extends DomainException
{
    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        return new self("An order cannot move from [{$from->value}] to [{$to->value}].");
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}

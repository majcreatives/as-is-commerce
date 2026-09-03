<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment providers the platform can transact through.
 *
 * An enum rather than a free string so a provider name cannot be misspelled
 * into a row that then fails to match its webhook events.
 */
enum PaymentProvider: string
{
    case Paystack = 'paystack';

    public function label(): string
    {
        return match ($this) {
            self::Paystack => 'Paystack',
        };
    }
}

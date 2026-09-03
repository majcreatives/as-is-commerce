<?php

declare(strict_types=1);

namespace App\Domain\Shared\Phone;

/**
 * Normalizes user-supplied phone numbers into a canonical E.164 string.
 *
 * Phone handling is isolated behind this contract so the Ghana-specific
 * implementation can be replaced (for example by libphonenumber, once the
 * platform supports more than one country) without touching call sites.
 */
interface PhoneNumberNormalizer
{
    /**
     * Return the E.164 representation, or null when the input is not valid.
     */
    public function normalize(string $input): ?string;

    public function isValid(string $input): bool;

    /**
     * Render an E.164 number in the local format users expect to read.
     */
    public function forDisplay(string $e164): string;
}

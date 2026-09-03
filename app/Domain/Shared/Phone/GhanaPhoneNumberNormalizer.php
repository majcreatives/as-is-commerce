<?php

declare(strict_types=1);

namespace App\Domain\Shared\Phone;

/**
 * Ghana (+233) phone number normalization.
 *
 * Accepts the formats Ghanaian users actually type -- 0244123456,
 * 244123456, 233244123456, +233 24 412 3456 -- and reduces them all to
 * a single canonical E.164 value so the database can enforce uniqueness.
 */
final class GhanaPhoneNumberNormalizer implements PhoneNumberNormalizer
{
    public const COUNTRY_CODE = '233';

    /**
     * National destination codes for Ghanaian mobile networks.
     *
     * Kept as data rather than a regex so new network allocations are a
     * one-line change. Landline codes are deliberately excluded: the
     * platform sends OTPs, which requires a mobile line.
     *
     * @var list<string>
     */
    public const MOBILE_PREFIXES = [
        '20', '23', '24', '25', '26', '27', '28', '29',
        '50', '53', '54', '55', '56', '57', '59',
    ];

    public function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            return null;
        }

        // Reduce every accepted form to the 9-digit national number.
        $national = match (true) {
            // 233244123456 (12 digits, country code prefixed)
            str_starts_with($digits, self::COUNTRY_CODE) && strlen($digits) === 12 => substr($digits, 3),
            // 0244123456 (10 digits, national trunk prefix)
            str_starts_with($digits, '0') && strlen($digits) === 10 => substr($digits, 1),
            // 244123456 (9 digits, bare national number)
            strlen($digits) === 9 => $digits,
            default => null,
        };

        if ($national === null || ! $this->hasKnownMobilePrefix($national)) {
            return null;
        }

        return '+'.self::COUNTRY_CODE.$national;
    }

    public function isValid(string $input): bool
    {
        return $this->normalize($input) !== null;
    }

    public function forDisplay(string $e164): string
    {
        $national = str_starts_with($e164, '+'.self::COUNTRY_CODE)
            ? substr($e164, 4)
            : $e164;

        if (strlen($national) !== 9) {
            return $e164;
        }

        // 0XX XXX XXXX -- the grouping Ghanaian users read most easily.
        return sprintf(
            '0%s %s %s',
            substr($national, 0, 2),
            substr($national, 2, 3),
            substr($national, 5, 4),
        );
    }

    private function hasKnownMobilePrefix(string $national): bool
    {
        return in_array(substr($national, 0, 2), self::MOBILE_PREFIXES, true);
    }
}

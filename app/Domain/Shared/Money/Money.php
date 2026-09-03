<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An exact monetary amount, held as integer minor units.
 *
 * For Ghana: 1 GH₵ = 100 pesewas, so GH₵ 100.00 is 10000.
 *
 * Float and double are never used anywhere in this class -- not for storage,
 * not for parsing, not for arithmetic. Parsing splits the decimal string and
 * works on the two halves as integers, because `(int) round((float) '0.29' *
 * 100)` is exactly the kind of representation error that silently loses money.
 *
 * Instances are immutable; every operation returns a new Money.
 */
final readonly class Money implements JsonSerializable
{
    public const MINOR_UNITS_PER_MAJOR = 100;

    private function __construct(
        public int $minor,
        public string $currency,
    ) {}

    /**
     * Build from integer minor units (pesewas).
     */
    public static function fromMinor(int $minor, string $currency = 'GHS'): self
    {
        return new self($minor, self::normalizeCurrency($currency));
    }

    public static function zero(string $currency = 'GHS'): self
    {
        return new self(0, self::normalizeCurrency($currency));
    }

    /**
     * Build from a decimal string such as "5500", "5500.50" or "-12.05".
     *
     * Accepts a string rather than a float so the caller cannot introduce a
     * representation error before the value even reaches us. Thousands
     * separators and surrounding whitespace are tolerated; anything else is
     * rejected rather than silently coerced.
     */
    public static function fromDecimalString(string $amount, string $currency = 'GHS'): self
    {
        $normalized = str_replace([' ', ',', "\u{00A0}"], '', trim($amount));

        if (! preg_match('/^(?<sign>[+-])?(?<major>\d+)(?:\.(?<fraction>\d+))?$/', $normalized, $m)) {
            throw new InvalidArgumentException("Not a valid monetary amount: [{$amount}].");
        }

        $fraction = $m['fraction'] ?? '';

        if (strlen($fraction) > 2) {
            throw new InvalidArgumentException(
                "Amount [{$amount}] has more precision than the currency supports."
            );
        }

        // Pad "5" to "50" so 5500.5 reads as 5500.50, not 5500.05.
        $fraction = str_pad($fraction, 2, '0', STR_PAD_RIGHT);

        $minor = ((int) $m['major'] * self::MINOR_UNITS_PER_MAJOR) + (int) $fraction;

        // The sign group is always present (empty when unmatched), because a
        // later group in the pattern always participates.
        if ($m['sign'] === '-') {
            $minor = -$minor;
        }

        return new self($minor, self::normalizeCurrency($currency));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Apply a rate expressed in basis points (1000 bps = 10%).
     *
     * Integer arithmetic throughout, rounding half up on the final division so
     * the result is deterministic rather than dependent on IEEE-754 behaviour.
     */
    public function percentageBps(int $basisPoints): self
    {
        $numerator = $this->minor * $basisPoints;
        $denominator = 10_000;

        $quotient = intdiv(abs($numerator), $denominator);
        $remainder = abs($numerator) % $denominator;

        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return new self($numerator < 0 ? -$quotient : $quotient, $this->currency);
    }

    /**
     * The decimal representation, e.g. "5500.00". Built by string assembly,
     * never by dividing through a float.
     */
    public function toDecimalString(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);

        return sprintf(
            '%s%d.%02d',
            $sign,
            intdiv($absolute, self::MINOR_UNITS_PER_MAJOR),
            $absolute % self::MINOR_UNITS_PER_MAJOR,
        );
    }

    /**
     * Human-readable amount with thousands separators, e.g. "5,500.00".
     *
     * The currency symbol is deliberately not included: symbols belong to the
     * presentation layer, which reads them from application settings.
     */
    public function format(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);

        return sprintf(
            '%s%s.%02d',
            $sign,
            number_format(intdiv($absolute, self::MINOR_UNITS_PER_MAJOR)),
            $absolute % self::MINOR_UNITS_PER_MAJOR,
        );
    }

    /**
     * @return array{minor: int, currency: string}
     */
    public function toArray(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency];
    }

    /**
     * @return array{minor: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Currency must be a 3-letter ISO 4217 code, got [{$currency}].");
        }

        return $currency;
    }
}

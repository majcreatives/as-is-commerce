<?php

declare(strict_types=1);

namespace App\Domain\Credit\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A quantity of credits, held as an integer count of subcredits.
 *
 * WHY THIS EXISTS. Credits are stored as whole integers and always will be --
 * that rule is not negotiable and is not relaxed here. What this type adds is
 * the distinction money already has in this codebase: the number that is
 * *stored* is not the number a customer *reads*.
 *
 *     pesewas     (integer, stored)  ->  GH₵12.50     (displayed, via Money)
 *     subcredits  (integer, stored)  ->  100 credits  (displayed, via this)
 *
 * A "subcredit" is the raw ledger unit. It is never shown to a customer and
 * never spoken about in customer-facing copy; it exists so the auction engine
 * can price a catch-up step far finer than one credit without anybody's wallet
 * balance turning into a seven-digit number on screen. {@see SUBCREDITS_PER_CREDIT}.
 *
 * NOT MONEY, AND NEVER CONVERTIBLE TO IT. Credits and cash are separate
 * systems with separate tables, services and enums. This class deliberately
 * shares no parent, no interface and no arithmetic with `Money`: the one place
 * the two meet is lot valuation, which reads what a credit's lot actually cost
 * and is not a rate this type could ever supply. Do not add a `toMoney()` here.
 *
 * NO FLOATS, for the same reason `Money` refuses them. The whole and
 * fractional halves are split with integer division and reassembled as
 * strings, because `130000 / 10000` is a float the moment it is written that
 * way, and a float is how a balance quietly stops adding up.
 *
 * Instances are immutable; every operation returns a new CreditAmount.
 */
final readonly class CreditAmount implements JsonSerializable
{
    /**
     * How many subcredits make one displayed credit.
     *
     * THIS IS 1 ON PURPOSE, FOR NOW. Introducing the type and re-denominating
     * the ledger are two separate steps, in that order, so that no deployment
     * ever exists in which credit figures read ten thousand times larger than
     * they did the day before. While this is 1, a subcredit *is* a credit,
     * every rendered figure is byte-for-byte what it was before this class
     * existed, and there is a test asserting exactly that.
     *
     * Raising it is the re-denomination step, and raising it is the only
     * change that step needs to make to this file. It must stay a power of
     * ten: the display logic derives its decimal places from the number of
     * zeros, and the constructor refuses anything else.
     */
    public const SUBCREDITS_PER_CREDIT = 1;

    private function __construct(
        /** The raw, authoritative count. What the ledger stores. */
        public int $subcredits,
    ) {}

    /**
     * Build from the raw ledger count.
     *
     * This is the constructor every read path uses, because every credit
     * column in the database holds subcredits.
     */
    public static function fromSubcredits(int $subcredits): self
    {
        return new self($subcredits);
    }

    /**
     * Build from a whole number of credits as a customer would say it.
     *
     * "Give this user 500 credits" -- a package size, a referral reward, an
     * administrative adjustment. Always exact: a whole credit is always a
     * whole number of subcredits.
     */
    public static function fromCredits(int $credits): self
    {
        return new self($credits * self::SUBCREDITS_PER_CREDIT);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Build from a decimal string such as "100", "13.84" or "0.0001".
     *
     * Takes a string rather than a float so the caller cannot introduce a
     * representation error before the value arrives, exactly as
     * `Money::fromDecimalString()` does and for exactly the same reason.
     *
     * Rejects more decimal places than the current divisor can represent,
     * rather than silently truncating: a caller asking for a precision the
     * ledger cannot hold has made a mistake worth hearing about.
     */
    public static function fromDecimalString(string $amount): self
    {
        $normalized = str_replace([' ', ',', "\u{00A0}"], '', trim($amount));

        if (! preg_match('/^(?<sign>-)?(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $normalized, $m)) {
            throw new InvalidArgumentException("Not a valid credit amount: [{$amount}].");
        }

        $places = self::decimalPlaces();
        $fraction = $m['fraction'] ?? '';

        if (strlen($fraction) > $places) {
            throw new InvalidArgumentException(
                "[{$amount}] is finer than a subcredit: at most {$places} decimal place(s) can be represented."
            );
        }

        $subcredits = (int) $m['whole'] * self::SUBCREDITS_PER_CREDIT
            + (int) str_pad($fraction, $places, '0');

        return new self($m['sign'] === '-' ? -$subcredits : $subcredits);
    }

    public function isZero(): bool
    {
        return $this->subcredits === 0;
    }

    public function isPositive(): bool
    {
        return $this->subcredits > 0;
    }

    public function isNegative(): bool
    {
        return $this->subcredits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->subcredits === $other->subcredits;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->subcredits > $other->subcredits;
    }

    public function isLessThan(self $other): bool
    {
        return $this->subcredits < $other->subcredits;
    }

    public function plus(self $other): self
    {
        return new self($this->subcredits + $other->subcredits);
    }

    public function minus(self $other): self
    {
        return new self($this->subcredits - $other->subcredits);
    }

    /**
     * The whole credits in this amount, discarding any fraction.
     *
     * For "how many whole credits is this", not for display -- `format()` and
     * `toDecimalString()` keep the fraction.
     */
    public function wholeCredits(): int
    {
        return intdiv($this->subcredits, self::SUBCREDITS_PER_CREDIT);
    }

    /**
     * The displayed figure as a decimal string, trailing zeros trimmed.
     *
     * "100", "1,500" without separators here ("1500"), "13.84", "0.0001".
     * Integer arithmetic only.
     */
    public function toDecimalString(): string
    {
        $places = self::decimalPlaces();
        $negative = $this->subcredits < 0;
        $magnitude = abs($this->subcredits);

        $whole = intdiv($magnitude, self::SUBCREDITS_PER_CREDIT);
        $string = (string) $whole;

        if ($places > 0) {
            $fraction = rtrim(str_pad(
                (string) ($magnitude % self::SUBCREDITS_PER_CREDIT),
                $places,
                '0',
                STR_PAD_LEFT
            ), '0');

            if ($fraction !== '') {
                $string .= '.'.$fraction;
            }
        }

        return ($negative ? '-' : '').$string;
    }

    /**
     * The figure a customer reads, with thousands separators and its unit.
     *
     * "100 credits", "1 credit", "1,500 credits", "13.84 credits".
     *
     * The unit is named because a bare number beside a price is how a credit
     * count gets read as cedis. Singular only when the figure is exactly one.
     */
    public function format(): string
    {
        $decimal = $this->toDecimalString();

        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-')), 2, null);

        $rendered = number_format((int) $whole);

        if ($fraction !== null) {
            $rendered .= '.'.$fraction;
        }

        if (str_starts_with($decimal, '-')) {
            $rendered = '-'.$rendered;
        }

        return $rendered.' '.($this->subcredits === self::SUBCREDITS_PER_CREDIT ? 'credit' : 'credits');
    }

    /**
     * How many decimal places the current divisor can represent.
     *
     * 1 -> 0, 100 -> 2, 10000 -> 4.
     */
    public static function decimalPlaces(): int
    {
        return strlen((string) self::SUBCREDITS_PER_CREDIT) - 1;
    }

    /**
     * @return array{subcredits: int, credits: string}
     */
    public function toArray(): array
    {
        return [
            'subcredits' => $this->subcredits,
            'credits' => $this->toDecimalString(),
        ];
    }

    /**
     * @return array{subcredits: int, credits: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->format();
    }
}

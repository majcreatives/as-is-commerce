<?php

declare(strict_types=1);

use App\Domain\Credit\ValueObjects\CreditAmount;

/*
 * CreditAmount separates the number the ledger STORES (subcredits) from the
 * number a customer READS (credits), exactly as Money separates pesewas from
 * GH₵. It exists so the auction engine can price a catch-up step far finer
 * than one credit without a wallet balance turning into a seven-digit number.
 *
 * The divisor is 10,000, raised by 2026_09_22_100000_redenominate_credits_to_subcredits,
 * which converts every stored count by the same factor. This group of tests
 * replaces the ones that held while the divisor was still 1 (see git history
 * for that version, which proved the introduction of this type changed
 * nothing a customer could see) with the conversion behaviour those tests
 * predicted: a Starter pack is 1,000,000 subcredits and still reads
 * "100 credits"; a mid-auction figure now genuinely needs its decimal places.
 */

describe('at the current divisor, a whole credit reads as it always has', function (): void {
    it('formats a Starter pack as "100 credits", not a seven-digit number', function (): void {
        expect(CreditAmount::SUBCREDITS_PER_CREDIT)->toBe(10_000)
            ->and(CreditAmount::fromCredits(100)->format())->toBe('100 credits')
            ->and(CreditAmount::fromCredits(100)->subcredits)->toBe(1_000_000);
    });

    it('formats round credit counts with no decimal point', function (int $credits, string $expected) {
        expect(CreditAmount::fromCredits($credits)->format())->toBe($expected);
    })->with([
        'zero' => [0, '0 credits'],
        'one is singular' => [1, '1 credit'],
        'two is plural' => [2, '2 credits'],
        'a typical bid' => [150, '150 credits'],
        'a starter pack' => [100, '100 credits'],
        'a pro pack' => [1_000, '1,000 credits'],
        'crosses a thousands separator' => [1_500, '1,500 credits'],
        'several separators' => [1_234_567, '1,234,567 credits'],
    ]);

    it('carries four decimal places, trimmed to a whole number when the fraction is zero', function (): void {
        expect(CreditAmount::decimalPlaces())->toBe(4)
            ->and(CreditAmount::fromSubcredits(1_500 * CreditAmount::SUBCREDITS_PER_CREDIT)->toDecimalString())->toBe('1500');
    });

    it('gives the bare grouped number for a column already labelled "Credits"', function (): void {
        expect(CreditAmount::fromCredits(1_500)->formatNumber())->toBe('1,500')
            ->and(CreditAmount::fromSubcredits(-500 * CreditAmount::SUBCREDITS_PER_CREDIT)->formatNumber())->toBe('-500')
            ->and(CreditAmount::fromDecimalString('13.84')->formatNumber())->toBe('13.84');
    });
});

describe('a mid-auction figure genuinely needs its decimal places', function (): void {
    it('shows a fine catch-up step as a small decimal, not a raw subcredit count', function (): void {
        // A single catch-up step, at the fineness the re-denomination exists
        // to enable: one subcredit is the smallest possible bid.
        expect(CreditAmount::fromSubcredits(1)->toDecimalString())->toBe('0.0001')
            ->and(CreditAmount::fromSubcredits(1)->format())->toBe('0.0001 credits');
    });

    it('shows a bidder\'s running total the way the room would', function (): void {
        // 13.84 credits committed so far -- legible, not a wall of zeros.
        expect(CreditAmount::fromDecimalString('13.84')->format())->toBe('13.84 credits');
    });
});

/*
 * The properties below must hold at ANY divisor, so they are written in terms
 * of the constant rather than hard-coded counts. They are what the
 * re-denomination step will be checked against.
 */

describe('properties that hold at any divisor', function (): void {
    it('round-trips a whole number of credits', function (int $credits): void {
        expect(CreditAmount::fromCredits($credits)->wholeCredits())->toBe($credits);
    })->with([[0], [1], [100], [500], [1_000], [123_456]]);

    it('renders a whole number of credits without a decimal point', function (): void {
        expect(CreditAmount::fromCredits(100)->toDecimalString())->toBe('100')
            ->and(CreditAmount::fromCredits(100)->format())->toBe('100 credits');
    });

    it('says "credit" for exactly one and "credits" for everything else', function (): void {
        expect(CreditAmount::fromCredits(1)->format())->toBe('1 credit')
            ->and(CreditAmount::fromCredits(2)->format())->toBe('2 credits')
            ->and(CreditAmount::zero()->format())->toBe('0 credits');
    });

    it('keeps the divisor a power of ten so decimal places are derivable', function (): void {
        expect(CreditAmount::SUBCREDITS_PER_CREDIT)->toBeGreaterThanOrEqual(1)
            ->and((string) CreditAmount::SUBCREDITS_PER_CREDIT)
            ->toMatch('/^10*$/', 'The display logic derives its decimal places from the number of zeros in the divisor.');
    });

    it('adds and subtracts on the raw count', function (): void {
        $a = CreditAmount::fromCredits(150);
        $b = CreditAmount::fromCredits(50);

        expect($a->plus($b)->wholeCredits())->toBe(200)
            ->and($a->minus($b)->wholeCredits())->toBe(100);
    });

    it('compares without converting to a float', function (): void {
        $small = CreditAmount::fromCredits(1);
        $large = CreditAmount::fromCredits(2);

        expect($large->isGreaterThan($small))->toBeTrue()
            ->and($small->isLessThan($large))->toBeTrue()
            ->and($small->equals(CreditAmount::fromCredits(1)))->toBeTrue()
            ->and($small->equals($large))->toBeFalse();
    });

    it('knows its sign', function (): void {
        expect(CreditAmount::zero()->isZero())->toBeTrue()
            ->and(CreditAmount::fromCredits(1)->isPositive())->toBeTrue()
            ->and(CreditAmount::fromSubcredits(-1)->isNegative())->toBeTrue();
    });

    it('parses a whole number from a string', function (): void {
        expect(CreditAmount::fromDecimalString('500')->wholeCredits())->toBe(500);
    });

    it('tolerates separators and surrounding space, as Money does', function (): void {
        expect(CreditAmount::fromDecimalString(' 1,500 ')->wholeCredits())->toBe(1_500);
    });

    it('refuses a figure that is not a number', function (): void {
        expect(fn () => CreditAmount::fromDecimalString('many'))
            ->toThrow(InvalidArgumentException::class);
    });

    /*
     * Rejected rather than truncated. A caller asking for precision the ledger
     * cannot hold has made a mistake, and silently rounding it away is how a
     * bid gets placed for an amount nobody asked for.
     */
    it('refuses a figure finer than a subcredit', function (): void {
        $tooFine = '0.'.str_repeat('0', CreditAmount::decimalPlaces()).'1';

        expect(fn () => CreditAmount::fromDecimalString($tooFine))
            ->toThrow(InvalidArgumentException::class);
    });

    it('survives a round trip through its own decimal string', function (int $subcredits): void {
        $original = CreditAmount::fromSubcredits($subcredits);

        expect(CreditAmount::fromDecimalString($original->toDecimalString())->subcredits)
            ->toBe($subcredits);
    })->with([[0], [1], [150], [1_000], [1_234_567]]);

    it('serializes both the stored and the displayed figure', function (): void {
        expect(CreditAmount::fromCredits(100)->toArray())
            ->toBe(['subcredits' => 100 * CreditAmount::SUBCREDITS_PER_CREDIT, 'credits' => '100']);
    });

    /*
     * Credits and cash are separate systems. This is asserted structurally
     * rather than trusted: no method here may hand back a Money, because the
     * only honest credit-to-cash valuation reads what a specific lot actually
     * cost and is not a rate a value object could supply.
     */
    it('offers no conversion to money', function (): void {
        $methods = get_class_methods(CreditAmount::class);

        expect($methods)->not->toContain('toMoney')
            ->and($methods)->not->toContain('value')
            ->and($methods)->not->toContain('inCash');
    });
});

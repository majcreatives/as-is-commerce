<?php

declare(strict_types=1);

use App\Domain\Credit\ValueObjects\CreditAmount;
use Illuminate\Support\Str;

/*
 * CreditAmount separates the number the ledger STORES (subcredits) from the
 * number a customer READS (credits), exactly as Money separates pesewas from
 * GH₵. It exists so the auction engine can price a catch-up step far finer
 * than one credit without a wallet balance turning into a seven-digit number.
 *
 * The divisor is 1 today. Introducing this type and re-denominating the ledger
 * are deliberately separate steps, so the first group of tests below defends
 * the property that makes that safe: WHILE THE DIVISOR IS 1, NOTHING A
 * CUSTOMER SEES CHANGES. That is the whole claim of this step, and it is
 * asserted rather than intended.
 */

describe('at a divisor of 1, rendering is unchanged', function (): void {
    /*
     * The literal implementation this component had before the value object
     * existed. If CreditAmount ever disagrees with it while the divisor is 1,
     * this step has broken something on a live screen.
     */
    $previousImplementation = fn (int $amount): string => number_format($amount).' '.Str::plural('credit', $amount);

    it('formats exactly as the old component did', function (int $amount) use ($previousImplementation): void {
        expect(CreditAmount::SUBCREDITS_PER_CREDIT)
            ->toBe(1, 'These equivalence tests only hold at a divisor of 1. If the re-denomination step has landed, this block should have been replaced by its conversion tests, not edited to pass.');

        expect(CreditAmount::fromSubcredits($amount)->format())
            ->toBe($previousImplementation($amount));
    })->with([
        'zero' => [0],
        'one is singular' => [1],
        'two is plural' => [2],
        'a typical bid' => [150],
        'a starter pack' => [100],
        'a pro pack' => [1_000],
        'crosses a thousands separator' => [1_500],
        'several separators' => [1_234_567],
    ]);

    it('treats a whole credit and a subcredit as the same thing', function (): void {
        expect(CreditAmount::fromCredits(500)->subcredits)->toBe(500);
    });

    it('carries no decimal places to trim', function (): void {
        expect(CreditAmount::decimalPlaces())->toBe(0)
            ->and(CreditAmount::fromSubcredits(1_500)->toDecimalString())->toBe('1500');
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

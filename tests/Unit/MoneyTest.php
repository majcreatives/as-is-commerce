<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Money;

/*
 * Money is the foundation every later financial stage sits on, so these tests
 * are about exactness rather than coverage. The specific thing being defended
 * is that no value ever passes through a float, because IEEE-754 cannot
 * represent most decimal fractions and the resulting drift is silent.
 */

it('converts cedis to pesewas exactly', function (string $input, int $expected): void {
    expect(Money::fromDecimalString($input)->minor)->toBe($expected);
})->with([
    'the stage requirement' => ['100.00', 10_000],
    'whole cedis' => ['5500', 550_000],
    'whole cedis with decimals' => ['5500.00', 550_000],
    'with pesewas' => ['5500.50', 550_050],
    'single decimal place pads' => ['5500.5', 550_050],
    'sub-cedi' => ['0.29', 29],
    'one pesewa' => ['0.01', 1],
    'zero' => ['0', 0],
    'thousands separator tolerated' => ['5,500.00', 550_000],
    'surrounding space tolerated' => ['  100.00  ', 10_000],
]);

/*
 * 0.29 and 1.15 are the canonical float traps: (int) (0.29 * 100) yields 28,
 * and (int) (1.15 * 100) yields 114. Integer parsing must not reproduce that.
 */
it('does not reproduce the classic float rounding errors', function (): void {
    expect(Money::fromDecimalString('0.29')->minor)->toBe(29)
        ->and(Money::fromDecimalString('1.15')->minor)->toBe(115)
        ->and(Money::fromDecimalString('8.20')->minor)->toBe(820)
        ->and(Money::fromDecimalString('1.10')->minor)->toBe(110);

    // Demonstrates the bug being avoided, so the intent stays legible.
    expect((int) (0.29 * 100))->toBe(28);
});

it('rejects amounts it cannot represent exactly', function (string $input): void {
    expect(fn (): Money => Money::fromDecimalString($input))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'too much precision' => '10.001',
    'not a number' => 'abc',
    'empty' => '',
    'double decimal' => '10.00.00',
    'currency symbol included' => 'GHS 100.00',
]);

it('round-trips through its decimal representation', function (int $minor): void {
    $money = Money::fromMinor($minor);

    expect(Money::fromDecimalString($money->toDecimalString())->minor)->toBe($minor);
})->with([0, 1, 29, 100, 550_000, 999_999_999]);

it('formats for display without a currency symbol', function (): void {
    expect(Money::fromMinor(550_000)->format())->toBe('5,500.00')
        ->and(Money::fromMinor(29)->format())->toBe('0.29')
        ->and(Money::fromMinor(100)->format())->toBe('1.00');
});

it('adds and subtracts without drift', function (): void {
    $total = Money::zero();

    // A hundred additions of 0.29 is 29.00 exactly. In floats it is not.
    foreach (range(1, 100) as $ignored) {
        $total = $total->plus(Money::fromDecimalString('0.29'));
    }

    expect($total->minor)->toBe(2_900)
        ->and($total->toDecimalString())->toBe('29.00');
});

it('applies a basis-point rate using integer arithmetic', function (): void {
    $price = Money::fromDecimalString('5500.00');

    expect($price->percentageBps(1000)->minor)->toBe(55_000)   // 10%
        ->and($price->percentageBps(0)->minor)->toBe(0)
        ->and($price->percentageBps(10_000)->minor)->toBe(550_000); // 100%
});

it('rounds a basis-point rate half up, deterministically', function (): void {
    // 5 pesewas at 50% is 2.5 pesewas, which must resolve to 3 every time.
    expect(Money::fromMinor(5)->percentageBps(5_000)->minor)->toBe(3);
});

it('refuses to combine different currencies', function (): void {
    expect(fn (): Money => Money::fromMinor(100, 'GHS')->plus(Money::fromMinor(100, 'USD')))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a currency that is not an ISO 4217 code', function (): void {
    expect(fn (): Money => Money::fromMinor(100, 'CEDIS'))
        ->toThrow(InvalidArgumentException::class);
});

it('handles negative amounts symmetrically', function (): void {
    $negative = Money::fromDecimalString('-12.05');

    expect($negative->minor)->toBe(-1_205)
        ->and($negative->isNegative())->toBeTrue()
        ->and($negative->toDecimalString())->toBe('-12.05');
});

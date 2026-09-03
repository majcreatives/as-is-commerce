<?php

declare(strict_types=1);

use App\Domain\Shared\Phone\GhanaPhoneNumberNormalizer;

beforeEach(function (): void {
    $this->normalizer = new GhanaPhoneNumberNormalizer;
});

/*
 * Normalization is the guard that stops one person holding two accounts on the
 * same physical line, so every input shape a user might type must collapse to
 * exactly one canonical value.
 */
it('normalizes every accepted input shape to the same E.164 value', function (string $input): void {
    expect($this->normalizer->normalize($input))->toBe('+233244123456');
})->with([
    'national trunk prefix' => '0244123456',
    'bare national number' => '244123456',
    'country code, no plus' => '233244123456',
    'full E.164' => '+233244123456',
    'spaced' => '024 412 3456',
    'dashed' => '024-412-3456',
    'parenthesised' => '(024) 412 3456',
]);

it('rejects numbers it cannot deliver an OTP to', function (string $input): void {
    expect($this->normalizer->normalize($input))->toBeNull();
})->with([
    'too short' => '02441234',
    'too long' => '024412345678',
    'landline prefix' => '0302123456',
    'unknown network prefix' => '0114123456',
    'wrong country' => '+2348012345678',
    'empty' => '',
    'letters only' => 'not a phone',
]);

it('formats a stored number back into the local grouping users read', function (): void {
    expect($this->normalizer->forDisplay('+233244123456'))->toBe('024 412 3456');
});

it('accepts every prefix it advertises as valid', function (): void {
    foreach (GhanaPhoneNumberNormalizer::MOBILE_PREFIXES as $prefix) {
        expect($this->normalizer->isValid('0'.$prefix.'1234567'))
            ->toBeTrue("prefix {$prefix} should be accepted");
    }
});

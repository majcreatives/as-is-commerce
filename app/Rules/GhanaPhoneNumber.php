<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a phone number is one the platform can actually reach.
 *
 * Delegates to the normalizer so validation and storage can never disagree
 * about what counts as a valid number.
 */
final class GhanaPhoneNumber implements ValidationRule
{
    public function __construct(
        private readonly PhoneNumberNormalizer $normalizer,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->normalizer->isValid($value)) {
            $fail('Enter a valid Ghanaian mobile number, for example 024 412 3456.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Shared\Phone\GhanaPhoneNumberNormalizer;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => self::uniqueGhanaPhone(),
            'phone_verified_at' => null,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A syntactically valid, unique Ghanaian mobile number in E.164 form.
     */
    private static function uniqueGhanaPhone(): string
    {
        $prefix = fake()->randomElement(GhanaPhoneNumberNormalizer::MOBILE_PREFIXES);

        return '+'.GhanaPhoneNumberNormalizer::COUNTRY_CODE
            .$prefix
            .fake()->unique()->numerify('#######');
    }

    public function withoutEmail(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => null,
            'email_verified_at' => null,
        ]);
    }

    public function phoneVerified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'phone_verified_at' => now(),
        ]);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }
}

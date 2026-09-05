<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 *
 * A Ghanaian address, shaped the way real ones are: a description and a
 * locality rather than a street number and a postcode.
 *
 * Phones are written already normalized, because the address book service
 * normalizes on the way in and a factory that produced un-normalized numbers
 * would let a test pass against data the application never stores.
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['Home', 'Work', 'The shop']),
            'recipient_name' => fake()->name(),
            'recipient_phone' => '+2332'.fake()->numerify('44######'),
            'address_line' => fake()->streetAddress(),
            'area' => fake()->randomElement(['East Legon', 'Osu', 'Adum', 'Tema Community 5']),
            'city' => fake()->randomElement(['Accra', 'Kumasi', 'Takoradi', 'Tamale']),
            'region' => fake()->randomElement(['Greater Accra', 'Ashanti', 'Western', 'Northern']),
            'digital_address' => null,
            'landmark' => null,
            'instructions' => null,
            'is_default' => false,
        ];
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    public function isDefault(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /**
     * Every column is guarded, so the model has no fillable attributes at all.
     * The factory writes them directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Address
    {
        $address = new Address;
        $address->forceFill($attributes);

        return $address;
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Delivery>
 *
 * Builds a delivery row directly, for tests about a state rather than about
 * how that state is reached.
 *
 * IT CHECKS NO ELIGIBILITY AND WRITES NO HISTORY. Both belong to the
 * lifecycle, and a delivery that skipped them is a state the application never
 * produces on its own. Anything testing the state machine, the order coupling,
 * concurrency or notifications should go through `DeliveryLifecycle`.
 *
 * The order and user must be supplied. Inventing them would produce a package
 * for an order nobody paid for, which every guard in the domain exists to
 * prevent.
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'AIC-D-'.now()->format('Ymd').'-'.mb_strtoupper(Str::random(8)),
            'status' => DeliveryStatus::Pending,
            'recipient_name' => fake()->name(),
            'recipient_phone' => '+2332'.fake()->numerify('44######'),
            'address_line' => fake()->streetAddress(),
            'area' => 'East Legon',
            'city' => 'Accra',
            'region' => 'Greater Accra',
            'attempts' => 0,
        ];
    }

    /**
     * A delivery with nowhere to go, which is how an auction winner's begins.
     */
    public function withoutAddress(): static
    {
        return $this->state(fn (): array => [
            'recipient_name' => null,
            'recipient_phone' => null,
            'address_line' => null,
            'area' => null,
            'city' => null,
            'region' => null,
        ]);
    }

    public function status(DeliveryStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            // Required by a CHECK constraint: a delivered package must say
            // when, because a row claiming an outcome with no evidence of it
            // is worse than no row.
            'delivered_at' => $status === DeliveryStatus::Delivered ? Carbon::now() : null,
        ]);
    }

    /**
     * Every column is guarded, so the model has no fillable attributes at all.
     * The factory writes them directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Delivery
    {
        $delivery = new Delivery;
        $delivery->forceFill($attributes);

        return $delivery;
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReferralStatus;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Referral>
 *
 * Builds a referral row directly, for tests about a state rather than about
 * how that state is reached.
 *
 * IT ISSUES NO CREDITS AND CHECKS NO RULES. Both belong to the actions, and a
 * referral that skipped them is a state the application never produces on its
 * own. Anything testing attribution, qualification, the reward or the ledger
 * should go through `AttributeReferral` and `RewardReferral`.
 *
 * Both users must be supplied. Inventing them would risk a self-referral,
 * which every constraint in the domain exists to prevent.
 */
class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code_used' => mb_strtoupper(Str::random(8)),
            'status' => ReferralStatus::Attributed,
            'attributed_at' => Carbon::now(),
        ];
    }

    /**
     * Every column is guarded, so the model has no fillable attributes at all.
     * The factory writes them directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Referral
    {
        $referral = new Referral;
        $referral->forceFill($attributes);

        return $referral;
    }
}

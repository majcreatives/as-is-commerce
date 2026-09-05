<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Actions;

use App\Domain\Referrals\Exceptions\ReferralNotAllowed;
use App\Domain\Referrals\Services\ReferralCodes;
use App\Enums\ReferralStatus;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Records that one customer introduced another.
 *
 * ONLY AT REGISTRATION, AND ONLY FROM A CODE. The referred customer is the
 * account being created; the referrer is resolved on the server from a code.
 * No request supplies a referrer id, so there is no value a browser could send
 * that would credit somebody with an introduction they did not make.
 *
 * ONE REFERRER, FOR EVER. `referred_user_id` is unique, so a second attribution
 * is a database refusal rather than an application check two concurrent
 * registrations could both pass -- and a trigger refuses any later change to
 * the pair. Re-attribution is not a thing an administrator does carefully; it
 * is a thing no code path can do.
 *
 * NO CREDITS ARE ISSUED HERE, AND NONE ARE PROMISED. Somebody signing up costs
 * nothing and earns nothing. The reward depends on whether they ever make a
 * qualifying purchase, which is a different action entirely.
 *
 * A BAD CODE IS NOT AN ERROR. Somebody mistyping a link, or following one from
 * a customer whose account has since gone, should still be able to register.
 * The attribution simply does not happen, and registration carries on.
 */
final class AttributeReferral
{
    public function __construct(
        private readonly ReferralCodes $codes,
    ) {}

    /**
     * Attribute a newly registered customer to whoever's code they used.
     *
     * Returns the referral, or null when there is nothing to record -- an
     * absent code, an unrecognised one, a self-referral, or an account that
     * somehow already has a referrer.
     *
     * Never throws into registration. Creating an account must not fail
     * because a referral link was wrong.
     */
    public function handle(User $referred, ?string $code): ?Referral
    {
        $normalized = $this->codes->normalize($code);

        if ($normalized === null) {
            return null;
        }

        $referrer = $this->codes->owner($normalized);

        if ($referrer === null) {
            Log::info('Referral code not recognised at registration', [
                'operation' => 'referral.unknown_code',
                'referred_user_id' => $referred->id,
            ]);

            return null;
        }

        // Checked here, and refused again by a CHECK constraint. Somebody
        // registering a second account with their own code is the most obvious
        // abuse there is, and it is stopped in both places.
        if ($referrer->id === $referred->id) {
            Log::info('Self-referral refused', [
                'operation' => 'referral.self_referral',
                'user_id' => $referred->id,
            ]);

            return null;
        }

        try {
            return $this->record($referrer, $referred, $normalized);
        } catch (UniqueConstraintViolationException) {
            // This account already has a referrer. The database recognised it,
            // which is the point of the index -- and the existing relationship
            // stands, because that is what "one referrer for ever" means.
            Log::info('Referral attribution already exists', [
                'operation' => 'referral.already_attributed',
                'referred_user_id' => $referred->id,
            ]);

            return Referral::where('referred_user_id', $referred->id)->first();
        }
    }

    /**
     * The same thing, for a caller that wants to be told why it failed.
     *
     * Used by administrative tooling rather than by registration, which must
     * never fail over a referral.
     *
     * @throws ReferralNotAllowed
     */
    public function handleOrFail(User $referred, ?string $code): Referral
    {
        $normalized = $this->codes->normalize($code);
        $referrer = $normalized === null ? null : $this->codes->owner($normalized);

        if ($referrer === null) {
            throw ReferralNotAllowed::unknownCode();
        }

        if ($referrer->id === $referred->id) {
            throw ReferralNotAllowed::selfReferral();
        }

        if (Referral::where('referred_user_id', $referred->id)->exists()) {
            throw ReferralNotAllowed::alreadyAttributed();
        }

        try {
            return $this->record($referrer, $referred, $normalized);
        } catch (UniqueConstraintViolationException) {
            throw ReferralNotAllowed::alreadyAttributed();
        }
    }

    private function record(User $referrer, User $referred, string $code): Referral
    {
        $referral = new Referral;

        $referral->referrer_user_id = $referrer->id;
        $referral->referred_user_id = $referred->id;
        // The code as it was actually used, copied rather than referenced.
        $referral->code_used = $code;
        $referral->status = ReferralStatus::Attributed;
        $referral->attributed_at = Carbon::now();
        $referral->save();

        Log::info('Referral attributed', [
            'operation' => 'referral.attributed',
            'referral_id' => $referral->id,
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        return $referral;
    }
}

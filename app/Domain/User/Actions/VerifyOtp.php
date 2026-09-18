<?php

declare(strict_types=1);

namespace App\Domain\User\Actions;

use App\Domain\Settings\SettingsRepository;
use App\Domain\User\Exceptions\InvalidOtpException;
use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Validates and consumes a one-time code.
 *
 * Consuming happens inside the same transaction and row lock as validation,
 * so two submissions of the same code cannot both succeed: the first marks the
 * row used, the second finds nothing usable.
 *
 * Only the single most recent unconsumed, unexpired code for a user and
 * purpose is ever considered. An older code is dead by construction once a
 * newer one was issued.
 *
 * On success the caller performs the effect the code was issued for (marking
 * the email verified, resetting the password). On any failure the code is
 * neither consumed (unless it exhausted its attempts) nor usable again.
 */
final class VerifyOtp
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function handle(User $user, OtpPurpose $purpose, string $code): void
    {
        // When codes are switched off, verification is refused exactly like an
        // unknown code: never a clue that distinguishes this state from a
        // wrong digit.
        if (! $this->settings->getBool('otp_enabled', true)) {
            throw InvalidOtpException::notFound();
        }

        // Rejections must persist (attempt counts, exhausted consumption), so
        // the failure is returned out of the transaction -- committed -- and
        // only thrown afterwards.
        $failure = DB::transaction(function () use ($user, $purpose, $code): ?InvalidOtpException {
            $record = OtpCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return InvalidOtpException::notFound();
            }

            if ($record->attempts >= self::MAX_ATTEMPTS) {
                // Exhausted codes are consumed so they cannot be ground through
                // indefinitely; the user requests a fresh code instead.
                $record->consumed_at = now();
                $record->save();

                return InvalidOtpException::attemptsExhausted();
            }

            if (! Hash::check($code, $record->code_hash)) {
                $record->attempts += 1;
                $record->save();

                return InvalidOtpException::mismatch();
            }

            $record->consumed_at = now();
            $record->save();

            return null;
        });

        if ($failure instanceof InvalidOtpException) {
            throw $failure;
        }
    }
}

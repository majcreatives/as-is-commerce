<?php

declare(strict_types=1);

namespace App\Domain\User\Actions;

use App\Domain\Settings\SettingsRepository;
use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Exceptions\OtpCooldownException;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\Exceptions\OtpDisabledException;
use App\Domain\User\ValueObjects\OtpDelivery;
use App\Enums\OtpPurpose;
use App\Enums\OtpTransport;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issues a one-time code to an account.
 *
 * The destination is ALWAYS the account's own stored address -- never anything
 * the browser supplied -- so an issued code can only travel to somewhere the
 * account already says it owns.
 *
 * The lifecycle is enforced here:
 *
 * - a resend during the cooldown window is refused;
 * - the stored code is hashed, so the table cannot double as a list of usable
 *   codes;
 * - the code carries a server-side expiry;
 * - if the transport cannot deliver it, the row is rolled back so a code that
 *   never reached the recipient can never be entered later.
 *
 * "Fails loud" is deliberate (AGENTS.md §74): an OTP flow that looks
 * successful while nothing was delivered would lock the user out of the very
 * action the code was meant to unlock.
 */
final class SendOtp
{
    public const LENGTH = 6;

    public const TTL_MINUTES = 10;

    public const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly OtpChannel $channel,
        private readonly SettingsRepository $settings,
    ) {}

    public function handle(User $user, OtpPurpose $purpose): OtpCode
    {
        if (! $this->settings->getBool('otp_enabled', true)) {
            throw OtpDisabledException::make();
        }

        $transport = OtpTransport::Mail;
        $destination = $user->email;

        if ($destination === null || $destination === '') {
            throw OtpDeliveryException::noDestination();
        }

        $this->assertNotOnCooldown($user, $purpose);

        $code = str_pad(
            (string) random_int(0, (10 ** self::LENGTH) - 1),
            self::LENGTH,
            '0',
            STR_PAD_LEFT,
        );

        return DB::transaction(function () use ($user, $purpose, $transport, $destination, $code): OtpCode {
            $record = OtpCode::create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'channel' => $transport,
                'destination' => $destination,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            try {
                // Email is the only transport today (AGENTS.md §74 defers SMS);
                // when SMS is approved the transport resolution changes here and
                // a phone destination is attached.
                $this->channel->send(new OtpDelivery(
                    code: $code,
                    purpose: $purpose,
                    email: $destination,
                ));
            } catch (OtpDeliveryException $e) {
                $record->delete();

                throw $e;
            }

            return $record;
        });
    }

    private function assertNotOnCooldown(User $user, OtpPurpose $purpose): void
    {
        $latest = OtpCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return;
        }

        $elapsed = (int) now()->diffInSeconds($latest->created_at, absolute: true);

        if ($elapsed < self::COOLDOWN_SECONDS) {
            throw new OtpCooldownException(self::COOLDOWN_SECONDS - $elapsed);
        }
    }
}

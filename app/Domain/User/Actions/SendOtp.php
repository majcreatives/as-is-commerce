<?php

declare(strict_types=1);

namespace App\Domain\User\Actions;

use App\Domain\Settings\SettingsRepository;
use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Exceptions\OtpCooldownException;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\Exceptions\OtpDisabledException;
use App\Domain\User\Support\SmsOtpChannel;
use App\Domain\User\ValueObjects\OtpDelivery;
use App\Enums\OtpPurpose;
use App\Enums\OtpTransport;
use App\Models\OtpCode;
use App\Models\User;
use DateTimeImmutable;
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
        /**
         * The mail channel, held as the interface rather than the concrete
         * class so a test can substitute a transport that fails on demand --
         * which is how the "delivery failure rolls the code back" guarantee is
         * actually proven.
         */
        private readonly OtpChannel $mailChannel,
        private readonly SmsOtpChannel $smsChannel,
        private readonly SettingsRepository $settings,
    ) {}

    public function handle(User $user, OtpPurpose $purpose, ?OtpTransport $prefer = null): OtpCode
    {
        if (! $this->settings->getBool('otp_enabled', true)) {
            throw OtpDisabledException::make();
        }

        $transport = $this->resolveTransport($user, $purpose, $prefer);

        $destination = $transport === OtpTransport::Sms ? $user->phone : $user->email;

        $this->assertNotOnCooldown($user, $purpose);

        $code = str_pad(
            (string) random_int(0, (10 ** self::LENGTH) - 1),
            self::LENGTH,
            '0',
            STR_PAD_LEFT,
        );

        $expiresAt = now()->addMinutes(self::TTL_MINUTES);

        return DB::transaction(function () use ($user, $purpose, $transport, $destination, $code, $expiresAt): OtpCode {
            $record = OtpCode::create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'channel' => $transport,
                'destination' => $destination,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => $expiresAt,
            ]);

            try {
                $this->sendThrough($transport, new OtpDelivery(
                    code: $code,
                    purpose: $purpose,
                    email: $transport === OtpTransport::Mail ? $destination : null,
                    phone: $transport === OtpTransport::Sms ? $destination : null,
                    expiresAt: DateTimeImmutable::createFromInterface($expiresAt),
                ));
            } catch (OtpDeliveryException $e) {
                $record->delete();

                throw $e;
            }

            return $record;
        });
    }

    /**
     * Decide which transport carries this code.
     *
     * `$prefer` is a request from the calling flow for a particular transport,
     * because the customer has told us which of their own details they used --
     * "I gave you my phone number, so send the code to my phone". It is a
     * hint about the account, never a destination: the code still only ever
     * goes to the address stored on the account, and a preference the account
     * cannot satisfy is a failure rather than a silent fallback to somewhere
     * the customer is not looking.
     *
     * With no preference, email wins wherever the account has one. It is
     * free, it is the transport already proven in production, and it is
     * reachable from any device, so there is no reason to spend money on a
     * message when the account can be reached for nothing. Only once there is
     * no email at all does a phone become the fallback, and only for password
     * reset: that is the gap SMS exists to close, since email is optional at
     * registration by design and a customer who signed up with a phone alone
     * otherwise has no way back in. Verification purposes stay email-only,
     * because sending a code to a phone number nobody has confirmed they
     * control would claim ownership the platform has not established.
     *
     * SMS is gated on its own setting, so turning the channel on is a
     * deliberate operator action rather than a side effect of deploying this
     * code. Delivery still fails loudly if the provider is unconfigured or the
     * sender is unregistered; the switch only means it is never reached by
     * accident.
     */
    private function resolveTransport(User $user, OtpPurpose $purpose, ?OtpTransport $prefer): OtpTransport
    {
        if ($prefer === OtpTransport::Sms) {
            if ($purpose !== OtpPurpose::PasswordReset
                || ! $this->settings->getBool('sms_enabled', false)
                || blank($user->phone)
            ) {
                throw OtpDeliveryException::noDestinationForSms();
            }

            return OtpTransport::Sms;
        }

        if ($prefer === OtpTransport::Mail) {
            if (blank($user->email)) {
                throw OtpDeliveryException::noDestination();
            }

            return OtpTransport::Mail;
        }

        if (! blank($user->email)) {
            return OtpTransport::Mail;
        }

        if ($purpose === OtpPurpose::PasswordReset
            && $this->settings->getBool('sms_enabled', false)
            && ! blank($user->phone)
        ) {
            return OtpTransport::Sms;
        }

        // Every remaining case is an account with no email, and the remedy is
        // the same in all of them: add one. SMS being switched off, or this
        // flow not being wired to text, both leave the email as the only way
        // in.
        throw OtpDeliveryException::noDestination();
    }

    private function sendThrough(OtpTransport $transport, OtpDelivery $delivery): void
    {
        match ($transport) {
            OtpTransport::Mail => $this->mailChannel->send($delivery),
            OtpTransport::Sms => $this->smsChannel->send($delivery),
        };
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

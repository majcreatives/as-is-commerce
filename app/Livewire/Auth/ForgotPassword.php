<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Actions\SendOtp;
use App\Domain\User\Exceptions\OtpCooldownException;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\Exceptions\OtpDisabledException;
use App\Enums\OtpPurpose;
use App\Enums\OtpTransport;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The request step of password recovery.
 *
 * Accepts either of the two things a customer remembers about their own
 * account: the email address or the phone number. Email being optional at
 * registration means a phone-only customer previously had no way back in at
 * all -- this closes that, and nothing else about the flow changes.
 *
 * The two branches are treated identically in every way that matters. Only a
 * value that actually resolves to an account reaches the code issuer, and
 * every other outcome -- unknown value, malformed number, code switched off,
 * delivery failure, resend inside the cooldown -- produces one identical
 * message. The page can never be used to discover which phone numbers or
 * addresses are registered.
 */
#[Layout('components.layouts.guest')]
#[Title('Forgot your password?')]
class ForgotPassword extends Component
{
    /**
     * The email address or phone number the customer typed.
     */
    public string $identifier = '';

    public function send(): void
    {
        // Only length and presence are validated here. Enforcing an email or
        // phone format would tell a caller which shape of input was expected,
        // which is a needless distinction to draw on a form whose whole
        // purpose is to say nothing about who holds an account.
        $this->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        // Captured before anything clears the field. The throttle key is built
        // from the identifier, so computing it after the reset would record
        // every failure against one empty key and let a caller walk straight
        // past the limit.
        $throttleKey = $this->throttleKey();

        $this->ensureIsNotRateLimited($throttleKey);

        $match = $this->findAccount($this->identifier);

        if ($match !== null) {
            [$user, $transport] = $match;

            try {
                app(SendOtp::class)->handle($user, OtpPurpose::PasswordReset, $transport);

                // The account, not the string that was typed, carries the reset
                // forward. The code still has to be presented to prove ownership.
                // No address is copied into the session: the reset step reads the
                // account's own current value, so a customer who updates their
                // number or address mid-reset sees where the code actually went.
                session()->put('password_reset_user_id', $user->id);
                session()->put('password_reset_channel', $transport->value);

                $this->redirectRoute('password.reset', navigate: true);

                return;
            } catch (OtpCooldownException|OtpDeliveryException|OtpDisabledException) {
                // A delivery hiccup or disabled codes must not distinguish an
                // existing account from a missing one.
            }
        }

        // One identical message whether the value matched an account, matched
        // nothing, or the delivery failed: the page neither confirms nor
        // denies the existence of an account.
        session()->flash(
            'status',
            'If an account exists for that email address or phone number, we have sent a one-time code to it.',
        );

        $this->reset('identifier');

        RateLimiter::hit($throttleKey);
    }

    /**
     * Resolve a typed value to an account and the transport it should use.
     *
     * Returns null when the value matches nothing. The two lookups cannot
     * overlap -- a value containing "@" is only ever tried as an email, a
     * value without one is only ever tried as a phone -- so the result is
     * never ambiguous and never a candidate for two accounts.
     *
     * The transport follows the value the customer actually used. Asking with
     * a phone number and being sent a code to an email address they may not
     * check is how a recovery flow convinces someone it is broken, so the
     * customer's own detail decides the channel, not a server-side preference.
     * The destination is still the account's stored value, never the input.
     *
     * @return array{0: User, 1: OtpTransport}|null
     */
    private function findAccount(string $value): ?array
    {
        $trimmed = trim($value);

        if (str_contains($trimmed, '@')) {
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($trimmed)])
                ->first();

            return $user === null ? null : [$user, OtpTransport::Mail];
        }

        $e164 = app(PhoneNumberNormalizer::class)->normalize($trimmed);

        if ($e164 === null) {
            return null;
        }

        $user = User::query()->where('phone', $e164)->first();

        return $user === null ? null : [$user, OtpTransport::Sms];
    }

    private function ensureIsNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 3)) {
            return;
        }

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'identifier' => "Too many requests. Please try again in {$seconds} seconds.",
        ]);
    }

    /**
     * Throttle per value rather than per session, so one customer cannot walk
     * away from the limit by typing a different address, and a shared network
     * cannot be used to spray a single account.
     */
    private function throttleKey(): string
    {
        return 'forgot-password:'.mb_strtolower(trim($this->identifier));
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}

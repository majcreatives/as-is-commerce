<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Actions\VerifyOtp;
use App\Domain\User\Exceptions\InvalidOtpException;
use App\Enums\OtpPurpose;
use App\Enums\OtpTransport;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Reset your password')]
class ResetPassword extends Component
{
    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Which channel the outstanding code is travelling by, for wording only.
     */
    private string $channel = OtpTransport::Mail->value;

    /**
     * A partly hidden form of the account's own address, so the customer can
     * see where the code went without seeing the whole thing.
     */
    private string $destinationHint = '';

    public function mount(): void
    {
        $user = $this->pendingAccount();

        if ($user === null) {
            $this->redirectRoute('password.request', navigate: true);

            return;
        }

        $channel = session('password_reset_channel');

        $this->channel = is_string($channel) ? $channel : OtpTransport::Mail->value;

        $this->destinationHint = $this->channel === OtpTransport::Sms->value
            ? app(PhoneNumberNormalizer::class)->forDisplay($user->phone)
            : $this->maskEmail((string) $user->email);
    }

    public function save(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $this->pendingAccount();

        if ($user === null) {
            $this->addError('code', 'The code is invalid or has expired.');

            return;
        }

        // Checked before the code is presented, and against the account rather
        // than against the code. VerifyOtp already allows five guesses per
        // issued code, which is the right place for that limit to live -- but
        // a caller who can ask for a fresh code would otherwise get five more
        // guesses every time, and an unbounded number of codes, and the inner
        // limit would never bind. This one is per account and per address, and
        // it survives a new code because it does not know about codes.
        $throttleKey = $this->throttleKey($user);
        $this->ensureIsNotRateLimited($throttleKey);

        try {
            app(VerifyOtp::class)->handle($user, OtpPurpose::PasswordReset, $this->code);
        } catch (InvalidOtpException) {
            RateLimiter::hit($throttleKey, self::THROTTLE_DECAY_SECONDS);

            $this->addError('code', 'The code is invalid or has expired.');

            return;
        }

        // Cleared on success, so a customer who mistyped a few times and then
        // succeeded is not left with a nearly-spent allowance.
        RateLimiter::clear($throttleKey);

        $user->update(['password' => $this->password]);

        session()->forget([
            'password_reset_user_id',
            'password_reset_channel',
        ]);

        session()->flash('status', 'Your password has been reset. Sign in with your new password.');

        $this->redirectRoute('login', navigate: true);
    }

    /**
     * Guesses allowed per account and address before a code is even checked.
     *
     * The window is an hour rather than the minute used on the sign-in form
     * because a one-time code is a long-lived secret: unlike a password check,
     * every attempt here is a genuine attempt to break a six-digit number, and
     * there is no reason for a legitimate customer to come back this often.
     */
    private const MAX_ATTEMPTS = 5;

    private const THROTTLE_DECAY_SECONDS = 3600;

    private function ensureIsNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, maxAttempts: self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($throttleKey);

        // Thrown, not merely reported. Recording the error and carrying on would
        // let the code be presented anyway, which would make the limit
        // decorative: it would count attempts without refusing any.
        throw ValidationException::withMessages([
            'code' => "Too many attempts. Request a new code and try again in {$this->humanize($seconds)}.",
        ]);
    }

    /**
     * Keyed on the account and the address together, so an attacker working on
     * somebody else's account exhausts their own allowance rather than locking
     * the real owner out of resetting their own password. The address is in the
     * key precisely so that a shared address -- a campus, an office -- does not
     * have its members spending one budget between them.
     */
    private function throttleKey(User $user): string
    {
        return 'password-reset:'.$user->id.'|'.request()->ip();
    }

    /**
     * Seconds as a phrase, so the message can say minutes rather than making a
     * customer do arithmetic on "3540".
     */
    private function humanize(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return $minutes <= 1
            ? 'a minute'
            : "{$minutes} minutes";
    }

    /**
     * The account with a reset in progress, or null when there is none.
     *
     * The id travels in the session from the request step, and that session is
     * only ever populated after a code was actually issued to this account. The
     * code remains what proves ownership: the id alone lets nobody reset
     * anything, exactly as the address used to. Re-read on every attempt
     * rather than cached, so an account closed between the two steps cannot
     * have a password written to it.
     */
    private function pendingAccount(): ?User
    {
        $userId = session('password_reset_user_id');

        if (! is_int($userId) && ! (is_string($userId) && ctype_digit($userId))) {
            return null;
        }

        return User::query()->find((int) $userId);
    }

    /**
     * Enough of the address to recognise it, not enough to read it off someone
     * else's shoulder. Only ever shown to the person who just proved the
     * account is theirs.
     */
    private function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return str_repeat('*', min(3, strlen($email))).'@';
        }

        return substr($email, 0, 1).'***'.substr($email, $at);
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password', [
            'destinationHint' => $this->destinationHint,
            'channel' => $this->channel,
        ]);
    }
}

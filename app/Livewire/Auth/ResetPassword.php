<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Actions\VerifyOtp;
use App\Domain\User\Exceptions\InvalidOtpException;
use App\Enums\OtpPurpose;
use App\Enums\OtpTransport;
use App\Models\User;
use Illuminate\Validation\Rules\Password;
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

        try {
            app(VerifyOtp::class)->handle($user, OtpPurpose::PasswordReset, $this->code);
        } catch (InvalidOtpException) {
            $this->addError('code', 'The code is invalid or has expired.');

            return;
        }

        $user->update(['password' => $this->password]);

        session()->forget([
            'password_reset_user_id',
            'password_reset_channel',
        ]);

        session()->flash('status', 'Your password has been reset. Sign in with your new password.');

        $this->redirectRoute('login', navigate: true);
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

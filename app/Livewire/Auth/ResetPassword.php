<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\User\Actions\VerifyOtp;
use App\Domain\User\Exceptions\InvalidOtpException;
use App\Enums\OtpPurpose;
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
    public string $email = '';

    public string $code = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        // The address travels in the session from the forgot-password step, so
        // nobody can reset a password for an address they do not receive codes
        // at: the code is what proves ownership, not the address itself.
        $email = session('password_reset_email');

        $exists = is_string($email)
            && User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists();

        if (! $exists) {
            $this->redirectRoute('password.request', navigate: true);

            return;
        }

        $this->email = $email;
    }

    public function save(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($this->email)])
            ->first();

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

        session()->forget('password_reset_email');

        session()->flash('status', 'Your password has been reset. Sign in with your new password.');

        $this->redirectRoute('login', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password');
    }
}

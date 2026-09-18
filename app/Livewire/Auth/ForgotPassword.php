<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\User\Actions\SendOtp;
use App\Domain\User\Exceptions\OtpCooldownException;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\Exceptions\OtpDisabledException;
use App\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Forgot your password?')]
class ForgotPassword extends Component
{
    public string $email = '';

    public function send(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $this->ensureIsNotRateLimited();

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($this->email)])
            ->first();

        if ($user !== null) {
            try {
                app(SendOtp::class)->handle($user, OtpPurpose::PasswordReset);
                session()->put('password_reset_email', $user->email);

                $this->redirectRoute('password.reset', navigate: true);

                return;
            } catch (OtpCooldownException|OtpDeliveryException|OtpDisabledException) {
                // A delivery hiccup or disabled codes must not distinguish an
                // existing account from a missing one.
            }
        }

        // One identical message whether the address matched an account, matched
        // nothing, or the delivery failed: the page neither confirms nor
        // denies the existence of an account.
        session()->flash(
            'status',
            'If an account exists for that address, we have sent a one-time code to it.',
        );

        $this->reset('email');

        RateLimiter::hit($this->throttleKey());
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 3)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many requests. Please try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(): string
    {
        return 'forgot-password:'.mb_strtolower($this->email);
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password');
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Sign in')]
class Login extends Component
{
    /** Phone number or email address. */
    public string $identifier = '';

    public string $password = '';

    public bool $remember = false;

    public function login(PhoneNumberNormalizer $normalizer): void
    {
        $this->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited();

        // An identifier containing "@" is treated as an email; anything else
        // is normalized as a phone number. The server decides which column to
        // match on -- the client never says.
        $credentials = Str::contains($this->identifier, '@')
            ? ['email' => $this->identifier, 'password' => $this->password]
            : ['phone' => $normalizer->normalize($this->identifier) ?? '', 'password' => $this->password];

        if (! Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // Deliberately generic: revealing whether the account exists would
            // let an attacker enumerate registered phone numbers.
            throw ValidationException::withMessages([
                'identifier' => 'These credentials do not match our records.',
            ]);
        }

        // Account standing is enforced after the password check so a
        // suspended account cannot be distinguished from a wrong password.
        if (! Auth::user()->canAuthenticate()) {
            $status = Auth::user()->status->label();
            Auth::logout();
            Session::invalidate();

            throw ValidationException::withMessages([
                'identifier' => "This account is {$status}. Contact support for assistance.",
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(route('dashboard', absolute: false), navigate: true);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'identifier' => "Too many login attempts. Try again in {$seconds} seconds.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->identifier).'|'.request()->ip());
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}

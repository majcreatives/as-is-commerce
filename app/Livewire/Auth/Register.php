<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Actions\RegisterUser;
use App\Models\User;
use App\Rules\GhanaPhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Create your account')]
class Register extends Component
{
    public string $first_name = '';

    public string $last_name = '';

    public string $phone = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * A referral code, when somebody arrived through a shared link.
     *
     * Read from the query string and nothing else. Attribution happens only at
     * registration, deliberately: no cookie, no session, no tracking window to
     * expire and no persistent browser state that could later override a
     * relationship somebody else established. The simplest safe design.
     *
     * Its value is never trusted -- the server resolves it to an account, and
     * an unrecognised code simply attributes nothing.
     */
    #[Url(as: 'ref')]
    public string $ref = '';

    public function register(
        RegisterUser $action,
        PhoneNumberNormalizer $normalizer,
        AttributeReferral $referrals,
    ): void {
        $this->validate([
            'first_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'phone' => ['required', 'string', new GhanaPhoneNumber($normalizer)],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        // Registration is a POST from the page, not a GET of it, so the page
        // throttle in routes/web.php says nothing about this. Without a limit
        // here an account can be created as fast as the form will submit, and
        // registration signs the new account straight in -- there is no code to
        // prove the phone number is theirs first -- so the accounts are
        // immediately usable for bidding.
        //
        // Keyed on the address alone, and only the address, because the thing
        // being limited is bulk creation and only the address is common to all
        // of it. The tension is real and worth stating: a shared address, which
        // is what a carrier-grade NAT or a campus or an office looks like from
        // here, spends one budget between everyone behind it. That is why the
        // ceiling is a day rather than a minute. A registration is a one-time
        // event per person, so nothing legitimate is lost by the generous
        // number, and if it ever does bite real customers the fix is a
        // challenge at the edge rather than a tighter limit here.
        $throttleKey = $this->throttleKey();
        $this->ensureIsNotRateLimited($throttleKey);

        // Uniqueness is checked against the canonical form so 0244123456 and
        // +233244123456 cannot become two accounts.
        $e164 = $normalizer->normalize($this->phone);

        if (User::where('phone', $e164)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'An account already exists for this phone number.',
            ]);
        }

        // The two name fields are stored in the single user.name column as
        // "First Last" -- no schema change, and an entirely blank name stays
        // blank in the database, exactly as before.
        $name = trim($this->first_name.' '.$this->last_name);

        if (mb_strlen($name) > 120) {
            throw ValidationException::withMessages([
                'last_name' => 'Your name is too long.',
            ]);
        }

        $user = $action->handle([
            'name' => $name !== '' ? $name : null,
            'phone' => $this->phone,
            'email' => $this->email !== '' ? $this->email : null,
            'password' => $this->password,
        ]);

        // Only once the account genuinely exists. A refused attempt, a
        // duplicate phone number or a mistyped field costs nothing, so the
        // ceiling above is never spent on a customer correcting a typo.
        RateLimiter::hit($throttleKey, self::THROTTLE_DECAY_SECONDS);

        // After the account exists, and never allowed to break registration:
        // a mistyped or expired referral link must not stop somebody joining.
        // The action resolves the code on the server, refuses a self-referral,
        // and does nothing at all when there is nothing to record.
        $referrals->handle($user, $this->ref !== '' ? $this->ref : null);

        Auth::login($user);
        Session::regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Accounts one address may create in a day.
     *
     * High on purpose, for the shared-address reason described in register().
     */
    private const MAX_ATTEMPTS = 20;

    private const THROTTLE_DECAY_SECONDS = 86_400;

    private function ensureIsNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, maxAttempts: self::MAX_ATTEMPTS)) {
            return;
        }

        // Thrown, not merely reported: recording the error and carrying on
        // would create the account anyway, which would make the limit
        // decorative -- counting registrations without refusing any.
        throw ValidationException::withMessages([
            'phone' => 'Too many accounts have been created from this connection. Please try again later.',
        ]);
    }

    private function throttleKey(): string
    {
        return 'register:'.request()->ip();
    }

    public function render(): View
    {
        return view('livewire.auth.register')
            ->layout('components.layouts.register-split');
    }
}

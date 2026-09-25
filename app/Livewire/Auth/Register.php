<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Actions\RegisterUser;
use App\Models\User;
use App\Rules\GhanaPhoneNumber;
use Illuminate\Support\Facades\Auth;
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

        // After the account exists, and never allowed to break registration:
        // a mistyped or expired referral link must not stop somebody joining.
        // The action resolves the code on the server, refuses a self-referral,
        // and does nothing at all when there is nothing to record.
        $referrals->handle($user, $this->ref !== '' ? $this->ref : null);

        Auth::login($user);
        Session::regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.register')
            ->layout('components.layouts.register-split');
    }
}

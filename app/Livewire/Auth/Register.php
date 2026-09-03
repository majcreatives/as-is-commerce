<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Actions\RegisterUser;
use App\Models\User;
use App\Rules\GhanaPhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Create your account')]
class Register extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(RegisterUser $action, PhoneNumberNormalizer $normalizer): void
    {
        $this->validate([
            'name' => ['nullable', 'string', 'max:120'],
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

        $user = $action->handle([
            'name' => $this->name !== '' ? $this->name : null,
            'phone' => $this->phone,
            'email' => $this->email !== '' ? $this->email : null,
            'password' => $this->password,
        ]);

        Auth::login($user);
        Session::regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.register');
    }
}

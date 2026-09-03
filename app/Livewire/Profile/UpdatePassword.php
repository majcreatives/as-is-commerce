<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Component;

class UpdatePassword extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(): void
    {
        $this->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        // The 'hashed' cast handles hashing; no plaintext ever reaches storage.
        Auth::user()->update(['password' => $this->password]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }

    public function render(): View
    {
        return view('livewire.profile.update-password');
    }
}

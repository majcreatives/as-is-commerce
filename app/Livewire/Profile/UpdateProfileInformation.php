<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Models\User;
use App\Rules\GhanaPhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

class UpdateProfileInformation extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public function mount(PhoneNumberNormalizer $normalizer): void
    {
        $user = Auth::user();

        $this->name = $user->name ?? '';
        $this->phone = $normalizer->forDisplay($user->phone);
        $this->email = $user->email ?? '';
    }

    public function save(PhoneNumberNormalizer $normalizer): void
    {
        $user = Auth::user();

        $this->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', new GhanaPhoneNumber($normalizer)],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $e164 = $normalizer->normalize($this->phone);

        if (User::where('phone', $e164)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'An account already exists for this phone number.',
            ]);
        }

        $user->name = $this->name !== '' ? $this->name : null;

        $newEmail = $this->email !== '' ? $this->email : null;
        if ($user->email !== $newEmail) {
            // A different (or newly added) address has never been proven to
            // belong to them: a verification earned at the old address must
            // not carry over. The OTP flow re-verifies the new one.
            $user->email = $newEmail;
            $user->email_verified_at = null;
        }

        // Changing the number invalidates any prior verification. The OTP
        // flow that re-verifies it arrives with the SMS provider; until then
        // the account simply reads as unverified rather than falsely verified.
        if ($user->phone !== $e164) {
            $user->phone = $e164;
            $user->phone_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated');
    }

    public function render(): View
    {
        return view('livewire.profile.update-profile-information');
    }
}

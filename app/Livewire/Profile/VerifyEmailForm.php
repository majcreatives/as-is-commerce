<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Domain\User\Actions\SendOtp;
use App\Domain\User\Actions\VerifyOtp;
use App\Domain\User\Exceptions\InvalidOtpException;
use App\Domain\User\Exceptions\OtpCooldownException;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\Exceptions\OtpDisabledException;
use App\Enums\OtpPurpose;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class VerifyEmailForm extends Component
{
    public string $code = '';

    public bool $sent = false;

    public bool $verified = false;

    public function mount(): void
    {
        // Defence in depth on top of the authed profile route: this component
        // must never run a mutation for a request that has no user.
        abort_unless(Auth::check(), 403);

        $this->verified = Auth::user()->hasVerifiedEmail();
    }

    public function send(): void
    {
        $user = Auth::user();

        if ($this->verified) {
            return;
        }

        $this->reset('code');

        try {
            app(SendOtp::class)->handle($user, OtpPurpose::EmailVerify);
            $this->sent = true;
        } catch (OtpCooldownException|OtpDeliveryException|OtpDisabledException $e) {
            $this->sent = false;
            $this->addError('code', $e->getMessage());
        }
    }

    public function verify(): void
    {
        $user = Auth::user();

        if ($this->verified) {
            return;
        }

        $this->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        try {
            app(VerifyOtp::class)->handle($user, OtpPurpose::EmailVerify, $this->code);
        } catch (InvalidOtpException) {
            // One deliberately vague message for every refusal, so an attacker
            // cannot distinguish a wrong digit from an expired code.
            $this->addError('code', 'The code is invalid or has expired.');

            return;
        }

        $user->email_verified_at = now();
        $user->save();

        $this->verified = true;
        $this->sent = false;
        $this->reset('code');

        $this->dispatch('email-verified');
    }

    public function render(): View
    {
        return view('livewire.profile.verify-email-form');
    }
}

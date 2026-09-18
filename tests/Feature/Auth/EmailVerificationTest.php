<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRepository;
use App\Domain\User\Actions\SendOtp;
use App\Domain\User\Actions\VerifyOtp;
use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Exceptions\InvalidOtpException;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\Exceptions\OtpDisabledException;
use App\Domain\User\ValueObjects\OtpDelivery;
use App\Enums\OtpPurpose;
use App\Livewire\Profile\VerifyEmailForm;
use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    seedSettings();

    $this->user = User::factory()->create([
        'phone' => '+233244123456',
        'email' => 'customer@example.test',
        'email_verified_at' => null,
        'password' => Hash::make('password123'),
    ]);
});

it('sends a six digit code to the account email when asked', function (): void {
    Mail::fake();
    $captured = null;

    Livewire::actingAs($this->user)
        ->test(VerifyEmailForm::class)
        ->call('send')
        ->assertSet('sent', true)
        ->assertHasNoErrors();

    Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$captured): bool {
        $captured = $mail->code;

        return $mail->hasTo($this->user->email) && $mail->purpose === OtpPurpose::EmailVerify;
    });

    expect($captured)->toMatch('/^\d{6}$/');

    $row = OtpCode::query()->where('user_id', $this->user->id)->sole();
    expect(Hash::check($captured, $row->code_hash))->toBeTrue();
    expect($row->purpose)->toBe(OtpPurpose::EmailVerify);
    expect($row->channel->value)->toBe('mail');
    expect($row->destination)->toBe('customer@example.test');
});

it('marks the email verified when the correct code is entered', function (): void {
    Mail::fake();

    Livewire::actingAs($this->user)->test(VerifyEmailForm::class)->call('send');
    $code = Mail::sent(OtpMail::class)->first()->code;

    Livewire::actingAs($this->user)
        ->test(VerifyEmailForm::class)
        ->set('code', $code)
        ->call('verify')
        ->assertSet('verified', true)
        ->assertSet('sent', false)
        ->assertHasNoErrors();

    expect($this->user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses a wrong code and counts the attempt', function (): void {
    Mail::fake();

    Livewire::actingAs($this->user)->test(VerifyEmailForm::class)->call('send');

    Livewire::actingAs($this->user)
        ->test(VerifyEmailForm::class)
        ->set('code', '111111')
        ->call('verify')
        ->assertHasErrors('code')
        ->assertSet('verified', false);

    $row = OtpCode::query()->where('user_id', $this->user->id)->sole();
    expect($row->attempts)->toBe(1);
});

it('never reveals whether the refusal was a wrong digit or an unknown code', function (): void {
    Mail::fake();

    Livewire::actingAs($this->user)->test(VerifyEmailForm::class)->call('send');

    $wrong = Livewire::actingAs($this->user)
        ->test(VerifyEmailForm::class)
        ->set('code', '111111')
        ->call('verify')
        ->errors()->first('code');

    $expired = Livewire::actingAs($this->user)
        ->test(VerifyEmailForm::class)
        ->call('send');

    OtpCode::query()->where('user_id', $this->user->id)->update(['expires_at' => now()->subMinute()]);

    $expiredCode = $expired->set('code', '222222')->call('verify')->errors()->first('code');

    $notFound = Livewire::actingAs($this->user)
        ->test(VerifyEmailForm::class)
        ->set('code', '333333')
        ->call('verify')
        ->errors()->first('code');

    expect($wrong)->toBe($expiredCode);
    expect($wrong)->toBe($notFound);
});

it('consumes a code on success so it cannot be replayed', function (): void {
    Mail::fake();

    app(SendOtp::class)->handle($this->user, OtpPurpose::EmailVerify);
    $code = Mail::sent(OtpMail::class)->first()->code;

    app(VerifyOtp::class)->handle($this->user, OtpPurpose::EmailVerify, $code);

    expect(fn () => app(VerifyOtp::class)->handle($this->user, OtpPurpose::EmailVerify, $code))
        ->toThrow(InvalidOtpException::class);
});

it('refuses a resend inside the cooldown window', function (): void {
    Mail::fake();

    Livewire::actingAs($this->user)->test(VerifyEmailForm::class)->call('send');

    Livewire::actingAs($this->user)
        ->test(VerifyEmailForm::class)
        ->call('send')
        ->assertHasErrors('code');

    expect(Mail::sent(OtpMail::class)->count())->toBe(1);
});

it('refuses an expired code', function (): void {
    Mail::fake();

    app(SendOtp::class)->handle($this->user, OtpPurpose::EmailVerify);
    $code = Mail::sent(OtpMail::class)->first()->code;

    OtpCode::query()->where('user_id', $this->user->id)->update(['expires_at' => now()->subMinute()]);

    expect(fn () => app(VerifyOtp::class)->handle($this->user, OtpPurpose::EmailVerify, $code))
        ->toThrow(InvalidOtpException::class);
});

it('consumes a code once the maximum attempts are exceeded', function (): void {
    Mail::fake();

    app(SendOtp::class)->handle($this->user, OtpPurpose::EmailVerify);
    $code = Mail::sent(OtpMail::class)->first()->code;
    // A different digit, guaranteed wrong.
    $wrong = ($code[0] === '0' ? '1' : '0').substr($code, 1);

    foreach (range(1, 6) as $ignored) {
        try {
            app(VerifyOtp::class)->handle($this->user, OtpPurpose::EmailVerify, $wrong);
        } catch (InvalidOtpException) {
            // Every submission is refused; the last one exhausts the code.
        }
    }

    $row = OtpCode::query()->where('user_id', $this->user->id)->sole();
    expect($row->attempts)->toBe(VerifyOtp::MAX_ATTEMPTS);
    expect($row->consumed_at)->not->toBeNull();
});

it('fails loudly when the account has no email to send to', function (): void {
    // Email is optional on the account, but a code to nowhere is a silent
    // lockout -- so asking for one must fail loudly instead.
    $noEmail = User::factory()->create(['phone' => '+233244123457', 'email' => null]);

    expect(fn () => app(SendOtp::class)->handle($noEmail, OtpPurpose::EmailVerify))
        ->toThrow(OtpDeliveryException::class);

    expect(OtpCode::query()->where('user_id', $noEmail->id)->count())->toBe(0);
});

it('rolls back an issued code when the delivery fails', function (): void {
    app()->instance(OtpChannel::class, new class implements OtpChannel
    {
        public function send(OtpDelivery $delivery): void
        {
            throw OtpDeliveryException::deliveryFailed();
        }
    });

    expect(fn () => app(SendOtp::class)->handle($this->user, OtpPurpose::EmailVerify))
        ->toThrow(OtpDeliveryException::class);

    expect(OtpCode::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('refuses to issue or verify codes while disabled', function (): void {
    app(SettingsRepository::class)->set('otp_enabled', false);

    expect(fn () => app(SendOtp::class)->handle($this->user, OtpPurpose::EmailVerify))
        ->toThrow(OtpDisabledException::class);

    expect(fn () => app(VerifyOtp::class)->handle($this->user, OtpPurpose::EmailVerify, '000000'))
        ->toThrow(InvalidOtpException::class);
});

it('is not reachable by guests', function (): void {
    Livewire::test(VerifyEmailForm::class)
        ->assertStatus(403);
});

it('shows a verified account without offering the code form', function (): void {
    $this->user->email_verified_at = now();
    $this->user->save();

    Livewire::actingAs($this->user->fresh())
        ->test(VerifyEmailForm::class)
        ->assertSet('verified', true)
        ->assertSee('Your email address is verified.')
        ->assertDontSee('One-time code');
});

<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRepository;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    seedSettings();

    $this->user = User::factory()->create([
        'phone' => '+233244123456',
        'email' => 'customer@example.test',
        'password' => Hash::make('old-password123'),
    ]);
});

it('serves the request step to anyone', function (): void {
    $this->get(route('password.request'))->assertOk();
});

it('sends a code and moves on for a known address', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send')
        ->assertRedirect(route('password.reset'));

    Mail::assertSent(OtpMail::class);

    expect(session('password_reset_user_id'))->toBe($this->user->id);
    expect(session('password_reset_channel'))->toBe('mail');
});

it('answers an unknown address with the identical message and no code', function (): void {
    Mail::fake();

    // The component's own re-render after the request must carry the one
    // message shared by every "nothing you can do here" branch.
    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'nobody@example.test')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('If an account exists for that email address or phone number, we have sent a one-time code to it.');

    Mail::assertNothingSent();
    expect(session('password_reset_user_id'))->toBeNull();
});

it('does not reveal whether an address is registered', function (): void {
    Mail::fake();

    // A known address, blocked by the cooldown of its own first send: this is
    // the closest a real account can get to the "not found" branch, and it must
    // answer with the same message as a completely unknown address.
    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send')
        ->assertRedirect(route('password.reset'));

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('If an account exists for that email address or phone number, we have sent a one-time code to it.');

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'ghost@example.test')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('If an account exists for that email address or phone number, we have sent a one-time code to it.');
});

it('does not resend inside the cooldown window', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send');

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send')
        ->assertHasNoErrors();

    expect(Mail::sent(OtpMail::class)->count())->toBe(1);
});

it('treats disabled codes exactly like an unknown address', function (): void {
    Mail::fake();
    app(SettingsRepository::class)->set('otp_enabled', false);

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send')
        ->assertHasNoErrors();

    Mail::assertNothingSent();
    expect(session('password_reset_user_id'))->toBeNull();
});

it('rate limits repeated requests', function (): void {
    Mail::fake();

    // Driven through the component rather than by seeding the bucket. The
    // component clears the field on every failed attempt, so a limiter that
    // read the field after clearing it would write every failure to one empty
    // key and never trip -- which is what the direct seeding used to hide.
    foreach (range(1, 3) as $ignored) {
        Livewire::test(ForgotPassword::class)
            ->set('identifier', 'ghost@example.test')
            ->call('send')
            ->assertHasNoErrors();
    }

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'ghost@example.test')
        ->call('send')
        ->assertHasErrors('identifier');

    expect(RateLimiter::attempts('forgot-password:ghost@example.test'))->toBeGreaterThanOrEqual(3);
});

it('sends someone with no pending reset away from the reset step', function (): void {
    $this->get(route('password.reset'))
        ->assertRedirect(route('password.request'));
});

it('renders the reset step only while a reset is pending', function (): void {
    $this->withSession(['password_reset_user_id' => $this->user->id, 'password_reset_channel' => 'mail'])
        ->get(route('password.reset'))
        ->assertOk();
});

it('resets the password with the correct code', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send');

    $code = Mail::sent(OtpMail::class)->first()->code;
    expect($code)->toMatch('/^\d{6}$/');

    Livewire::test(ResetPassword::class)
        ->set('code', $code)
        ->set('password', 'new-password123')
        ->set('password_confirmation', 'new-password123')
        ->call('save')
        ->assertRedirect(route('login'));

    $fresh = $this->user->fresh();
    expect(Hash::check('new-password123', $fresh->password))->toBeTrue();
    expect(Hash::check('old-password123', $fresh->password))->toBeFalse();

    expect(session('password_reset_user_id'))->toBeNull();
});

it('refuses to reset with a wrong code and leaves the password alone', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send');

    $code = Mail::sent(OtpMail::class)->first()->code;
    $wrong = ($code[0] === '0' ? '1' : '0').substr($code, 1);

    Livewire::test(ResetPassword::class)
        ->set('code', $wrong)
        ->set('password', 'new-password123')
        ->set('password_confirmation', 'new-password123')
        ->call('save')
        ->assertHasErrors('code');

    expect(Hash::check('old-password123', $this->user->fresh()->password))->toBeTrue();
});

it('resets the password only after the code is consumed', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send');

    $code = Mail::sent(OtpMail::class)->first()->code;

    Livewire::test(ResetPassword::class)
        ->set('code', $code)
        ->set('password', 'new-password123')
        ->set('password_confirmation', 'new-password123')
        ->call('save')
        ->assertRedirect(route('login'));

    // Re-establish the pending reset (the first one cleared it), then the same
    // code, offered again, must fail.
    session()->put('password_reset_user_id', $this->user->id);

    Livewire::test(ResetPassword::class)
        ->set('code', $code)
        ->set('password', 'another-password123')
        ->set('password_confirmation', 'another-password123')
        ->call('save')
        ->assertHasErrors('code');

    expect(Hash::check('new-password123', $this->user->fresh()->password))->toBeTrue();
});

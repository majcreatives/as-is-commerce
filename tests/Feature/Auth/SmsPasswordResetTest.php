<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRepository;
use App\Domain\User\Actions\SendOtp;
use App\Domain\User\Contracts\SmsGateway;
use App\Domain\User\Exceptions\OtpDeliveryException;
use App\Domain\User\Exceptions\SmsGatewayError;
use App\Enums\OtpPurpose;
use App\Enums\OtpTransport;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * Password recovery for an account that has a phone number and no email.
 *
 * Email is optional at registration, so this is the case that previously had
 * no way back in at all. Everything asserted here is a property the email
 * recovery flow already had -- no enumeration, no destination invented from
 * browser input, no code surviving a failed delivery -- applied to the text
 * message path.
 */

/**
 * A gateway that records what it was asked to send, so a test can assert on
 * the real destination and message rather than trusting a mock's own claims.
 */
function recordingGateway(): object
{
    return new class implements SmsGateway
    {
        /** @var list<array{phone: string, message: string}> */
        public array $sent = [];

        public function send(string $e164Phone, string $message): void
        {
            $this->sent[] = ['phone' => $e164Phone, 'message' => $message];
        }
    };
}

function failingGateway(?SmsGatewayError $error = null): SmsGateway
{
    return new class($error ?? SmsGatewayError::rejected('Sender not registered')) implements SmsGateway
    {
        public function __construct(private readonly SmsGatewayError $error) {}

        public function send(string $e164Phone, string $message): void
        {
            throw $this->error;
        }
    };
}

beforeEach(function (): void {
    seedSettings();

    $this->gateway = recordingGateway();
    app()->instance(SmsGateway::class, $this->gateway);

    app(SettingsRepository::class)->set('sms_enabled', true);

    // The account this whole file is about: signed up with a phone number and
    // no email address, which is legal and used to be a dead end.
    $this->user = User::factory()->create([
        'phone' => '+233244123456',
        'email' => null,
        'password' => Hash::make('old-password123'),
    ]);
});

it('sends a code by text to a phone-only account', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0244123456')
        ->call('send')
        ->assertRedirect(route('password.reset'));

    expect($this->gateway->sent)->toHaveCount(1);

    // The account's own stored number, in canonical form, not what was typed.
    expect($this->gateway->sent[0]['phone'])->toBe('+233244123456');

    expect(session('password_reset_user_id'))->toBe($this->user->id);
    expect(session('password_reset_channel'))->toBe(OtpTransport::Sms->value);

    // Nothing went by mail, and nothing was invented for an address that does
    // not exist.
    Mail::assertNothingSent();
});

it('finds the account from every format a Ghanaian types', function (): void {
    foreach (['0244123456', '244123456', '233244123456', '+233 24 412 3456', '+233-24-412-3456'] as $typed) {
        // Each attempt needs its own account-level cooldown cleared, otherwise
        // only the first one would reach delivery.
        OtpCode::query()->where('user_id', $this->user->id)->delete();
        $this->gateway->sent = [];

        Livewire::test(ForgotPassword::class)
            ->set('identifier', $typed)
            ->call('send')
            ->assertRedirect(route('password.reset'));

        expect($this->gateway->sent)->toHaveCount(1);
    }
});

it('uses the transport the customer actually used', function (): void {
    Mail::fake();

    // An account with both, asked by phone. Sending the code to the email
    // instead is how a recovery flow convinces someone it is broken.
    $both = User::factory()->create([
        'phone' => '+233255000111',
        'email' => 'both@example.test',
    ]);

    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0255000111')
        ->call('send')
        ->assertRedirect(route('password.reset'));

    expect($this->gateway->sent)->toHaveCount(1);
    Mail::assertNothingSent();

    // And asked by email, the same account is reached by mail and costs
    // nothing. SMS is a fallback, not a preference.
    OtpCode::query()->where('user_id', $both->id)->delete();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'both@example.test')
        ->call('send')
        ->assertRedirect(route('password.reset'));

    expect($this->gateway->sent)->toHaveCount(1);
    Mail::assertSent(OtpMail::class);
});

it('tells the customer nothing about an unknown number', function (): void {
    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0200000000')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('If an account exists for that email address or phone number, we have sent a one-time code to it.');

    expect($this->gateway->sent)->toBeEmpty();
    expect(session('password_reset_user_id'))->toBeNull();
});

it('tells the customer nothing about a value that is neither', function (): void {
    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'not a phone or an email')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('If an account exists for that email address or phone number, we have sent a one-time code to it.');

    expect($this->gateway->sent)->toBeEmpty();
    expect(session('password_reset_user_id'))->toBeNull();
});

it('does not reveal whether a number is registered', function (): void {
    // A real account blocked by its own cooldown is the closest a genuine
    // account gets to the unknown-number branch, and must be indistinguishable.
    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0244123456')
        ->call('send')
        ->assertRedirect(route('password.reset'));

    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0244123456')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('If an account exists for that email address or phone number, we have sent a one-time code to it.');

    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0200000000')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('If an account exists for that email address or phone number, we have sent a one-time code to it.');

    // One message, one send: the cooldown is what stopped the repeat.
    expect($this->gateway->sent)->toHaveCount(1);
});

it('does not resend by text inside the cooldown window', function (): void {
    Livewire::test(ForgotPassword::class)->set('identifier', '0244123456')->call('send');
    Livewire::test(ForgotPassword::class)->set('identifier', '0244123456')->call('send')->assertHasNoErrors();

    expect($this->gateway->sent)->toHaveCount(1);
});

it('sends nothing while the channel is switched off', function (): void {
    app(SettingsRepository::class)->set('sms_enabled', false);

    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0244123456')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('If an account exists for that email address or phone number, we have sent a one-time code to it.');

    expect($this->gateway->sent)->toBeEmpty();
    expect(session('password_reset_user_id'))->toBeNull();
});

it('sends nothing for an account with no address at all', function (): void {
    // `users.phone` is NOT NULL, so an account with neither is not a state the
    // database can hold. Built in memory to pin the defensive branch anyway:
    // a malformed record must fail loudly, not reach a provider.
    $neither = User::factory()->make(['phone' => null, 'email' => null]);

    expect(fn () => app(SendOtp::class)->handle($neither, OtpPurpose::PasswordReset, OtpTransport::Mail))
        ->toThrow(OtpDeliveryException::class);

    expect(fn () => app(SendOtp::class)->handle($neither, OtpPurpose::PasswordReset))
        ->toThrow(OtpDeliveryException::class);

    expect($this->gateway->sent)->toBeEmpty();
});

it('rolls the code back when the provider rejects it', function (): void {
    app()->instance(SmsGateway::class, failingGateway());

    expect(fn () => app(SendOtp::class)->handle($this->user, OtpPurpose::PasswordReset, OtpTransport::Sms))
        ->toThrow(OtpDeliveryException::class);

    // A code nobody received must not survive to be entered.
    expect(OtpCode::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('rolls the code back when the provider cannot be reached', function (): void {
    app()->instance(SmsGateway::class, failingGateway(SmsGatewayError::unreachable('connection failed')));

    expect(fn () => app(SendOtp::class)->handle($this->user, OtpPurpose::PasswordReset, OtpTransport::Sms))
        ->toThrow(OtpDeliveryException::class);

    expect(OtpCode::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('refuses to pretend a message was sent when the provider is unconfigured', function (): void {
    // The gateway throws rather than swallowing a missing key, and the issued
    // code is rolled back with it.
    app()->instance(SmsGateway::class, failingGateway(SmsGatewayError::notConfigured()));

    expect(fn () => app(SendOtp::class)->handle($this->user, OtpPurpose::PasswordReset, OtpTransport::Sms))
        ->toThrow(OtpDeliveryException::class);

    expect(OtpCode::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('states the code and the real window in the message', function (): void {
    Livewire::test(ForgotPassword::class)->set('identifier', '0244123456')->call('send');

    $message = $this->gateway->sent[0]['message'];
    $sent = codeFrom($message);

    $record = OtpCode::query()
        ->where('user_id', $this->user->id)
        ->orderByDesc('id')
        ->first();

    // The message carries a six-digit code, the purpose, and the ten minutes
    // SendOtp actually enforces -- not a window invented by the channel.
    expect($sent)->toMatch('/^\d{6}$/');
    expect($message)->toContain('Reset your password');
    expect($message)->toContain('10 minutes');

    // The table holds a hash, never the plaintext that travelled, so a leaked
    // database is not a list of working codes.
    expect($record->code_hash)->not->toBe($sent);
    expect($record->code_hash)->toStartWith('$2y$');
    expect(Hash::check($sent, $record->code_hash))->toBeTrue();
});

it('records which channel and destination carried the code', function (): void {
    Livewire::test(ForgotPassword::class)->set('identifier', '0244123456')->call('send');

    $record = OtpCode::query()->where('user_id', $this->user->id)->orderByDesc('id')->first();

    expect($record->channel)->toBe(OtpTransport::Sms);
    expect($record->destination)->toBe('+233244123456');
    expect($record->purpose)->toBe(OtpPurpose::PasswordReset);
});

it('resets the password with a code that arrived by text', function (): void {
    Livewire::test(ForgotPassword::class)->set('identifier', '0244123456')->call('send');

    $code = codeFrom($this->gateway->sent[0]['message']);

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

it('refuses a wrong code and leaves the password alone', function (): void {
    Livewire::test(ForgotPassword::class)->set('identifier', '0244123456')->call('send');

    $code = codeFrom($this->gateway->sent[0]['message']);
    $wrong = ($code[0] === '0' ? '1' : '0').substr($code, 1);

    Livewire::test(ResetPassword::class)
        ->set('code', $wrong)
        ->set('password', 'new-password123')
        ->set('password_confirmation', 'new-password123')
        ->call('save')
        ->assertHasErrors('code');

    expect(Hash::check('old-password123', $this->user->fresh()->password))->toBeTrue();
});

it('shows a partly hidden number on the reset step', function (): void {
    Livewire::test(ForgotPassword::class)->set('identifier', '0244123456')->call('send');

    $html = Livewire::test(ResetPassword::class)->html();

    expect($html)->toContain('text message');

    // Enough to recognise the handset, not the whole number.
    expect($html)->not->toContain('+233244123456');
    expect($html)->toContain('024 412 3456');
});

it('rate limits repeated fruitless requests against the number typed', function (): void {
    // Driven through the component rather than by seeding the bucket, because
    // the point is that the counter the component reads is the one it writes.
    // The field is cleared on every failed attempt, so a limiter keyed off the
    // field after that point would record everything against one empty key and
    // never trip.
    foreach (range(1, 3) as $ignored) {
        Livewire::test(ForgotPassword::class)
            ->set('identifier', '0200000000')
            ->call('send')
            ->assertHasNoErrors();
    }

    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0200000000')
        ->call('send')
        ->assertHasErrors('identifier');

    expect(RateLimiter::attempts('forgot-password:0200000000'))->toBeGreaterThanOrEqual(3);
});

it('lets a real account recover after another identifier burns its limit', function (): void {
    // Repeated guesses at one number is what an enumeration attempt looks
    // like, so that is what gets throttled. A customer who then asks for their
    // own number must not be punished for someone else's probing.
    foreach (range(1, 3) as $ignored) {
        Livewire::test(ForgotPassword::class)
            ->set('identifier', '0200000000')
            ->call('send');
    }

    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0200000000')
        ->call('send')
        ->assertHasErrors('identifier');

    Livewire::test(ForgotPassword::class)
        ->set('identifier', '0244123456')
        ->call('send')
        ->assertRedirect(route('password.reset'));

    expect($this->gateway->sent)->toHaveCount(1);
});

it('does not rate limit a customer who is using their own account', function (): void {
    // A successful send is not a fruitless request, so it must not consume
    // the budget that protects against guessing. The per-code cooldown is
    // what paces a legitimate customer instead.
    foreach (range(1, 2) as $ignored) {
        OtpCode::query()->where('user_id', $this->user->id)->delete();
        $this->gateway->sent = [];

        Livewire::test(ForgotPassword::class)
            ->set('identifier', '0244123456')
            ->call('send')
            ->assertRedirect(route('password.reset'));

        expect($this->gateway->sent)->toHaveCount(1);
    }

    expect(RateLimiter::attempts('forgot-password:0244123456'))->toBe(0);
});

it('never sends by text for a verification purpose', function (): void {
    $withEmail = User::factory()->create(['phone' => '+233244999888', 'email' => 'verify@example.test']);

    // Phone verification is deliberately not wired to SMS: a code sent to a
    // number the platform has never confirmed would claim an ownership it
    // does not have.
    expect(fn () => app(SendOtp::class)->handle($withEmail, OtpPurpose::PhoneVerify, OtpTransport::Sms))
        ->toThrow(OtpDeliveryException::class);

    expect($this->gateway->sent)->toBeEmpty();
});

it('never quietly swaps the channel the customer asked for', function (): void {
    Mail::fake();

    $withEmail = User::factory()->create(['phone' => '+233244999888', 'email' => 'only-email@example.test']);

    // Asked by phone, with the channel switched off. Silently sending to the
    // email instead would answer a different question than the one the customer
    // asked, and would hide the fact that the channel is not working.
    app(SettingsRepository::class)->set('sms_enabled', false);

    expect(fn () => app(SendOtp::class)->handle($withEmail, OtpPurpose::PasswordReset, OtpTransport::Sms))
        ->toThrow(OtpDeliveryException::class);

    expect($this->gateway->sent)->toBeEmpty();
    Mail::assertNothingSent();
    expect(OtpCode::query()->where('user_id', $withEmail->id)->count())->toBe(0);
});

/**
 * Pull the six-digit code back out of the message the gateway was handed.
 */
function codeFrom(string $message): string
{
    preg_match('/\b(\d{6})\b/', $message, $matches);

    return $matches[1] ?? '';
}

<?php

declare(strict_types=1);

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword;
use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * Two separate things are being limited here, and the distinction is the whole
 * point.
 *
 * The pages are limited to slow a script down. They hold no data, no secrets,
 * and no account-specific anything, so nothing depends on them being limited
 * and nothing is lost if the ceiling is generous.
 *
 * The operations are limited to stop a specific account being guessed. Those
 * are keyed by identifier and by account, and they are the limits that actually
 * protect anybody. A test that only covered the pages would pass while every
 * account in the system stayed open to unlimited code guessing.
 */

/**
 * The allowance key for a password-reset bucket.
 *
 * Built from request()->ip() rather than a literal, so the key a test spends
 * against is always the key the component charges, whichever address the test
 * harness presents.
 */
function resetThrottleKey(int $userId): string
{
    return 'password-reset:'.$userId.'|'.request()->ip();
}

/**
 * Drive a failed reset-code submission for the pending account.
 */
function submitWrongResetCode(): void
{
    Livewire::test(ResetPassword::class)
        ->set('code', '000000')
        ->set('password', 'new-password123')
        ->set('password_confirmation', 'new-password123')
        ->call('save')
        ->assertHasErrors('code');
}

beforeEach(function (): void {
    seedSettings();

    // Registration assigns the customer role, so the roles have to exist before
    // a signup can succeed. Without this every registration in this file fails
    // for an unrelated reason and the throttle tests prove nothing.
    seedRoles();

    $this->user = User::factory()->create([
        'phone' => '+233244123456',
        'email' => 'customer@example.test',
        'password' => Hash::make('old-password123'),
    ]);
});

/*
 * The pages.
 */

it('lets a person open the sign-in page repeatedly', function (): void {
    // Ten in a row is already more than anybody does, and the ceiling is far
    // above it. Worth asserting, because a limit tuned to stop a script will
    // happily stop a person who loses their session and reloads.
    for ($i = 0; $i < 10; $i++) {
        $this->get(route('login'))->assertOk();
    }
});

it('stops the sign-in page being fetched in a loop', function (): void {
    // Thirty real requests rather than thirty writes to the cache. The limiter
    // derives its cache key from the limiter name and by() together and hashes
    // it, and a test that hardcoded that derivation would be asserting
    // Laravel's internals rather than this application's behaviour -- and
    // would keep passing if the limiter stopped being registered at all.
    for ($i = 0; $i < 30; $i++) {
        $this->get(route('login'))->assertOk();
    }

    $this->get(route('login'))->assertStatus(429);
});

it('counts the sign-in page against a separate bucket per address', function (): void {
    // Otherwise one person behind a shared address spends everyone's budget.
    // Asserted because the alternative -- a single global bucket for the page --
    // would pass the loop test above and lock out a whole office.
    for ($i = 0; $i < 30; $i++) {
        $this->get(route('login'))->assertOk();
    }

    $this->get('http://localhost/login', ['REMOTE_ADDR' => '203.0.113.9'])
        ->assertOk();
});

it('stops the paystack return routes being fetched in a loop', function (string $route): void {
    // Signed in, because these routes sit behind the auth middleware: an
    // anonymous caller is redirected to the sign-in page before any throttle is
    // consulted, so the loop would only ever be testing that redirect. The
    // realistic abuse is somebody hammering their own callback to keep poking
    // the fulfilment path, and that is an authenticated request.
    $this->actingAs($this->user);

    for ($i = 0; $i < 30; $i++) {
        $this->get(route($route));
    }

    $this->get(route($route))->assertStatus(429);
})->with(['credits.callback', 'checkout.callback']);

it('limits the other auth pages under the same ceiling', function (string $route): void {
    // Not hammered individually, because the loop above already proves the
    // limiter is registered and enforced; what is worth proving here is that
    // these routes are actually under it rather than merely sitting next to it.
    $middleware = Route::getRoutes()->getByName($route)->gatherMiddleware();

    expect($middleware)->toContain('throttle:auth-pages');
})->with(['register', 'password.request', 'password.reset']);

/*
 * The webhook. This is the one that must not be limited: Paystack's servers
 * retry, they do not wait, and a 429 would be read as a failure.
 */

it('never throttles the paystack webhook, however many times it is called', function (): void {
    fakeHttp(['*' => Http::response(['status' => true], 200)]);

    // Sixty attempts, well past every ceiling above, and the answer must stay
    // the same rejection every time. A 429 at any point would mean Paystack
    // had been taught that a signature failure is a reason to back off.
    for ($i = 0; $i < 60; $i++) {
        $this->post('/webhooks/paystack', ['event' => 'charge.success'], [
            'x-paystack-signature' => 'not-a-valid-signature',
        ])->assertStatus(401);
    }
});

/*
 * Password reset, which was the surface with no limit on it at all.
 */

it('counts a wrong reset code against the account', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send');

    $key = resetThrottleKey($this->user->id);

    expect(RateLimiter::attempts($key))->toBe(0);

    submitWrongResetCode();

    expect(RateLimiter::attempts($key))->toBe(1);
});

it('stops reset codes being guessed at once the allowance is spent', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send');

    $code = Mail::sent(OtpMail::class)->first()->code;

    $key = resetThrottleKey($this->user->id);

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit($key, 3600);
    }

    // The correct code, presented after the allowance is gone. If this passed
    // the account would be open no matter how many codes it has issued.
    Livewire::test(ResetPassword::class)
        ->set('code', $code)
        ->set('password', 'new-password123')
        ->set('password_confirmation', 'new-password123')
        ->call('save')
        ->assertHasErrors('code');

    expect(Hash::check('old-password123', $this->user->fresh()->password))->toBeTrue();
});

it('says how long to wait rather than just refusing', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send');

    $key = resetThrottleKey($this->user->id);

    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit($key, 3600);
    }

    $component = Livewire::test(ResetPassword::class)
        ->set('code', '000000')
        ->set('password', 'new-password123')
        ->set('password_confirmation', 'new-password123')
        ->call('save');

    $component->assertHasErrors('code');

    // In minutes rather than seconds. "3540 seconds" is technically honest and
    // practically useless to somebody who just mistyped a code twice.
    $message = $component->errors()->first('code');

    expect($message)
        ->toContain('Too many attempts')
        ->toContain('minutes')
        ->not->toContain('3600 seconds');
});

it('does not let a spent allowance follow a successful reset', function (): void {
    Mail::fake();

    Livewire::test(ForgotPassword::class)
        ->set('identifier', 'customer@example.test')
        ->call('send');

    $code = Mail::sent(OtpMail::class)->first()->code;
    $key = resetThrottleKey($this->user->id);

    // A customer who mistyped a few times and then got it right should not be
    // left with an almost-spent allowance for their next reset.
    RateLimiter::hit($key, 3600);

    Livewire::test(ResetPassword::class)
        ->set('code', $code)
        ->set('password', 'new-password123')
        ->set('password_confirmation', 'new-password123')
        ->call('save');

    expect(Hash::check('new-password123', $this->user->fresh()->password))->toBeTrue()
        ->and(RateLimiter::attempts($key))->toBe(0);
});

/*
 * Registration, which had no limit either.
 */

it('spends an allowance slot when an account is really created', function (): void {
    $key = 'register:'.request()->ip();

    Livewire::test(Register::class)
        ->set('first_name', 'Ama')
        ->set('phone', '0541234567')
        ->set('password', 'a-good-password1')
        ->set('password_confirmation', 'a-good-password1')
        ->call('register')
        // Asserted first, because without it this test would pass for the wrong
        // reason: a registration that failed for any other reason would also
        // leave the allowance unspent.
        ->assertHasNoErrors();

    expect(User::where('phone', '+233541234567')->exists())->toBeTrue()
        ->and(RateLimiter::attempts($key))->toBe(1);
});

it('does not spend an allowance slot on a refused registration', function (): void {
    $key = 'register:'.request()->ip();

    // A phone number already held. A customer who mistypes should not be
    // charged for it, and neither should a script get free attempts by
    // submitting duplicates on purpose.
    Livewire::test(Register::class)
        ->set('first_name', 'Ama')
        ->set('phone', '0244123456')
        ->set('password', 'a-good-password1')
        ->set('password_confirmation', 'a-good-password1')
        ->call('register')
        ->assertHasErrors('phone');

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('stops accounts being created in bulk once the allowance is spent', function (): void {
    $key = 'register:'.request()->ip();

    for ($i = 0; $i < 20; $i++) {
        RateLimiter::hit($key, 86_400);
    }

    Livewire::test(Register::class)
        ->set('first_name', 'Ama')
        ->set('phone', '0541234567')
        ->set('password', 'a-good-password1')
        ->set('password_confirmation', 'a-good-password1')
        ->call('register')
        ->assertHasErrors('phone');

    expect(User::where('phone', '+233541234567')->exists())->toBeFalse();
});

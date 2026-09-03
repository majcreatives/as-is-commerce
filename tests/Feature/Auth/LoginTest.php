<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function (): void {
    seedRoles();

    $this->user = User::factory()->create([
        'phone' => '+233244123456',
        'email' => 'user@example.test',
        'password' => Hash::make('password123'),
    ]);
});

it('logs in with a phone number', function (): void {
    Livewire::test(Login::class)
        ->set('identifier', '0244123456')
        ->set('password', 'password123')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->user);
});

it('logs in with an email address', function (): void {
    Livewire::test(Login::class)
        ->set('identifier', 'user@example.test')
        ->set('password', 'password123')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->user);
});

it('rejects a wrong password', function (): void {
    Livewire::test(Login::class)
        ->set('identifier', '0244123456')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('identifier');

    $this->assertGuest();
});

/*
 * The error for an unknown account must be indistinguishable from the error
 * for a wrong password, or the login form becomes a way to enumerate which
 * phone numbers are registered.
 */
it('does not reveal whether an account exists', function (): void {
    $unknown = Livewire::test(Login::class)
        ->set('identifier', '0209999999')
        ->set('password', 'password123')
        ->call('login')
        ->errors()->first('identifier');

    $wrongPassword = Livewire::test(Login::class)
        ->set('identifier', '0244123456')
        ->set('password', 'wrong-password')
        ->call('login')
        ->errors()->first('identifier');

    expect($unknown)->toBe($wrongPassword);
});

it('refuses to sign in a suspended account', function (): void {
    // Assigned directly: status is intentionally not mass-assignable.
    $this->user->status = UserStatus::Suspended;
    $this->user->save();

    Livewire::test(Login::class)
        ->set('identifier', '0244123456')
        ->set('password', 'password123')
        ->call('login')
        ->assertHasErrors('identifier');

    $this->assertGuest();
});

it('refuses to sign in a banned account', function (): void {
    // Assigned directly: status is intentionally not mass-assignable.
    $this->user->status = UserStatus::Banned;
    $this->user->save();

    Livewire::test(Login::class)
        ->set('identifier', '0244123456')
        ->set('password', 'password123')
        ->call('login')
        ->assertHasErrors('identifier');

    $this->assertGuest();
});

it('throttles repeated failed attempts', function (): void {
    foreach (range(1, 5) as $ignored) {
        Livewire::test(Login::class)
            ->set('identifier', '0244123456')
            ->set('password', 'wrong-password')
            ->call('login');
    }

    Livewire::test(Login::class)
        ->set('identifier', '0244123456')
        ->set('password', 'password123')
        ->call('login')
        ->assertHasErrors('identifier');

    // Correct credentials are refused while the throttle is active.
    $this->assertGuest();
});

it('logs the user out and clears the session', function (): void {
    $this->actingAs($this->user)
        ->post(route('logout'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

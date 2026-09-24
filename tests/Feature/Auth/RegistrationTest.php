<?php

declare(strict_types=1);

use App\Livewire\Auth\Register;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    seedRoles();
});

it('registers a user with only a phone number and password', function (): void {
    Livewire::test(Register::class)
        ->set('phone', '024 412 3456')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $user = User::firstWhere('phone', '+233244123456');

    expect($user)->not->toBeNull()
        ->and($user->email)->toBeNull()
        ->and($user->name)->toBeNull()
        ->and($user->hasRole('customer'))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

it('stores the phone number in canonical form regardless of how it was typed', function (): void {
    Livewire::test(Register::class)
        ->set('phone', '+233 24 412 3456')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasNoErrors();

    expect(User::first()->phone)->toBe('+233244123456');
});

it('never stores the password in plain text', function (): void {
    Livewire::test(Register::class)
        ->set('phone', '0244123456')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register');

    expect(User::first()->password)->not->toBe('password123');
});

it('rejects a duplicate phone number', function (): void {
    User::factory()->create(['phone' => '+233244123456']);

    Livewire::test(Register::class)
        ->set('phone', '0244123456')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasErrors('phone');

    expect(User::where('phone', '+233244123456')->count())->toBe(1);
});

/*
 * The important half of duplicate detection: a number typed in a different
 * shape must still collide with the stored canonical form.
 */
it('rejects a duplicate phone number entered in a different format', function (): void {
    User::factory()->create(['phone' => '+233244123456']);

    Livewire::test(Register::class)
        ->set('phone', '244123456')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasErrors('phone');

    expect(User::count())->toBe(1);
});

it('rejects a duplicate email when one is supplied', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::test(Register::class)
        ->set('phone', '0244123456')
        ->set('email', 'taken@example.test')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasErrors('email');
});

it('allows many accounts without an email address', function (): void {
    User::factory()->withoutEmail()->count(2)->create();

    Livewire::test(Register::class)
        ->set('phone', '0244123456')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasNoErrors();

    expect(User::whereNull('email')->count())->toBe(3);
});

it('rejects an invalid phone number', function (): void {
    Livewire::test(Register::class)
        ->set('phone', '0302123456')
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('register')
        ->assertHasErrors('phone');

    expect(User::count())->toBe(0);
});

it('requires the password confirmation to match', function (): void {
    Livewire::test(Register::class)
        ->set('phone', '0244123456')
        ->set('password', 'password123')
        ->set('password_confirmation', 'different123')
        ->call('register')
        ->assertHasErrors('password');
});

it('renders the what-you-get panel alongside the form', function (): void {
    Livewire::test(Register::class)
        ->assertSee('What you get')
        ->assertSee('A shop in cedis, with auctions on some products.')
        ->assertSee('How credits and bidding work');
});

it('renders a show/hide toggle for the password fields', function (): void {
    Livewire::test(Register::class)
        ->assertSeeHtml('x-data="{ show: false }"')
        ->assertSeeHtml('x-bind:type="show ? ')
        ->assertSee('Show password', false);
});

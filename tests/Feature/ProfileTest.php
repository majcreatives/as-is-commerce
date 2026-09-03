<?php

declare(strict_types=1);

use App\Livewire\Profile\UpdatePassword;
use App\Livewire\Profile\UpdateProfileInformation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function (): void {
    seedRoles();

    $this->user = User::factory()->phoneVerified()->create([
        'phone' => '+233244123456',
        'password' => Hash::make('password123'),
    ]);
});

it('updates the name and email', function (): void {
    Livewire::actingAs($this->user)
        ->test(UpdateProfileInformation::class)
        ->set('name', 'Ama Mensah')
        ->set('email', 'ama@example.test')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->fresh())
        ->name->toBe('Ama Mensah')
        ->email->toBe('ama@example.test');
});

/*
 * A changed number has not been proven to belong to the user, so any prior
 * verification must not carry over to it.
 */
it('clears phone verification when the number changes', function (): void {
    expect($this->user->hasVerifiedPhone())->toBeTrue();

    Livewire::actingAs($this->user)
        ->test(UpdateProfileInformation::class)
        ->set('phone', '0209876543')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->fresh())
        ->phone->toBe('+233209876543')
        ->phone_verified_at->toBeNull();
});

it('keeps phone verification when the number is unchanged', function (): void {
    Livewire::actingAs($this->user)
        ->test(UpdateProfileInformation::class)
        ->set('name', 'Kofi Owusu')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->fresh()->hasVerifiedPhone())->toBeTrue();
});

it('rejects a phone number belonging to another account', function (): void {
    User::factory()->create(['phone' => '+233209876543']);

    Livewire::actingAs($this->user)
        ->test(UpdateProfileInformation::class)
        ->set('phone', '0209876543')
        ->call('save')
        ->assertHasErrors('phone');
});

it('rejects an email belonging to another account', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::actingAs($this->user)
        ->test(UpdateProfileInformation::class)
        ->set('email', 'taken@example.test')
        ->call('save')
        ->assertHasErrors('email');
});

it('changes the password when the current one is correct', function (): void {
    Livewire::actingAs($this->user)
        ->test(UpdatePassword::class)
        ->set('current_password', 'password123')
        ->set('password', 'new-password456')
        ->set('password_confirmation', 'new-password456')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('new-password456', $this->user->fresh()->password))->toBeTrue();
});

it('refuses to change the password without the current one', function (): void {
    Livewire::actingAs($this->user)
        ->test(UpdatePassword::class)
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password456')
        ->set('password_confirmation', 'new-password456')
        ->call('save')
        ->assertHasErrors('current_password');

    expect(Hash::check('password123', $this->user->fresh()->password))->toBeTrue();
});

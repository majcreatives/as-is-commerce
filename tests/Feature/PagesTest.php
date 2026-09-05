<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    seedRoles();
});

it('serves the public pages to guests', function (string $route): void {
    $this->get(route($route))->assertOk();
})->with([
    'home' => 'home',
    'auctions.index' => 'auctions.index',
    'how-it-works' => 'how-it-works',
]);

it('requires authentication for private pages', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with([
    'dashboard' => 'dashboard',
    'profile.edit' => 'profile.edit',
]);

it('serves the dashboard to an authenticated user', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Welcome back');
});

it('serves the profile page to an authenticated user', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk();
});

it('redirects an authenticated user away from the guest pages', function (string $route): void {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertRedirect();
})->with([
    'login' => 'login',
    'register' => 'register',
]);

/*
 * The dashboard must never invent numbers. Until the wallet and auction
 * stages exist, it shows empty states.
 */
it('shows honest empty states rather than fabricated activity', function (): void {
    // A brand new account: real zeros read from the real tables, and empty
    // states that say what to do rather than inventing activity.
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('bid on anything yet')
        ->assertSee('No orders yet')
        ->assertSee('Nothing in transit')
        // And the two credit figures are shown separately, never summed.
        ->assertSee('Available credits')
        ->assertSee('Credits committed')
        ->assertSee('Consumed on bids. Not returned, whether you won or lost.');
});

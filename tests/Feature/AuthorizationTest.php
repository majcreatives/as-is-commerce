<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    seedRoles();
});

it('seeds the roles the application depends on', function (string $role): void {
    expect(Role::where('name', $role)->exists())->toBeTrue();
})->with(RoleSeeder::ROLES);

it('can be seeded repeatedly without duplicating roles', function (): void {
    seedRoles();
    seedRoles();

    expect(Role::count())->toBe(count(RoleSeeder::ROLES));
});

it('blocks a customer from the admin area', function (): void {
    $customer = User::factory()->create();
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

it('blocks a user with no role at all from the admin area', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

it('blocks a guest from the admin area', function (): void {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

it('allows an admin into the admin area', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();
});

it('allows a super admin into the admin area', function (): void {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->get(route('admin.dashboard'))
        ->assertOk();
});

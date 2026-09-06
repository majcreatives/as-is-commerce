<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    seedRoles();

    // Permissions too, because every admin route now carries a `can:` check
    // as well as a role check. A role alone opens nothing -- which is the
    // point of gating on permissions rather than role names, and is what lets
    // a narrower administrative role exist later without editing routes.
    seedPermissions();
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

it('refuses the admin area to a role holder without the permission behind it', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    // Revoking it from the role is what makes this real: syncPermissions on
    // the user would leave the role's own grant in place, and the test would
    // assert nothing.
    Role::findByName('admin')->revokePermissionTo('admin.dashboard.view');

    $this->actingAs($admin->fresh())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

it('allows a super admin into the admin area', function (): void {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->get(route('admin.dashboard'))
        ->assertOk();
});

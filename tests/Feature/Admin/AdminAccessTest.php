<?php

declare(strict_types=1);

use App\Models\AuctionRuleset;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    seedPermissions();
});

// ------------------------------------------------------------- Permissions

it('creates every permission this stage owns', function (string $permission): void {
    expect(Permission::where('name', $permission)->exists())->toBeTrue();
})->with(PermissionSeeder::PERMISSIONS);

it('can be seeded repeatedly without duplicating permissions', function (): void {
    seedPermissions();
    seedPermissions();

    expect(Permission::whereIn('name', PermissionSeeder::PERMISSIONS)->count())
        ->toBe(count(PermissionSeeder::PERMISSIONS));
});

it('grants the stage permissions to both administrative roles', function (string $role): void {
    $user = userWithRole($role);

    foreach (PermissionSeeder::PERMISSIONS as $permission) {
        expect($user->can($permission))->toBeTrue("{$role} should hold {$permission}");
    }
})->with(['admin', 'super_admin']);

/*
 * Customers hold two permissions of their own -- credits.view and
 * wallets.view -- which govern their own wallet only. They must hold none of
 * the staff permissions.
 */
it('grants a customer none of the staff permissions', function (string $permission): void {
    $customer = userWithRole('customer');

    expect($customer->can($permission))->toBeFalse();
})->with(PermissionSeeder::staffPermissions());

it('grants a customer only their own wallet permissions', function (): void {
    $customer = userWithRole('customer');

    // getAllPermissions, not getPermissionNames: these are held through the
    // customer role rather than assigned directly to the user.
    expect($customer->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect(PermissionSeeder::CUSTOMER_PERMISSIONS)->sort()->values()->all());
});

/*
 * The distinction that keeps the admin screens closed: every customer can see
 * their own wallet, but only staff can see anyone else's.
 */
it('separates viewing your own wallet from inspecting another', function (): void {
    expect(userWithRole('customer')->can('wallets.view'))->toBeTrue()
        ->and(userWithRole('customer')->can('wallets.inspect'))->toBeFalse()
        ->and(userWithRole('admin')->can('wallets.inspect'))->toBeTrue();
});

/*
 * A super admin passes checks for permissions that have not been seeded onto
 * the role, so later stages can add capabilities without a re-seed leaving
 * the super admin locked out of them.
 */
it('lets a super admin pass a permission that does not exist yet', function (): void {
    expect(userWithRole('super_admin')->can('some.future.permission'))->toBeTrue()
        ->and(userWithRole('admin')->can('some.future.permission'))->toBeFalse();
});

// ------------------------------------------------------------------ Routes

$adminRoutes = [
    'admin.settings' => '/admin/settings',
    'admin.rulesets.index' => '/admin/rulesets',
    'admin.rulesets.create' => '/admin/rulesets/create',
];

it('redirects guests to login', function (string $path): void {
    $this->get($path)->assertRedirect(route('login'));
})->with($adminRoutes);

it('forbids a customer', function (string $path): void {
    $this->actingAs(userWithRole('customer'))->get($path)->assertForbidden();
})->with($adminRoutes);

it('forbids a user with no role', function (string $path): void {
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with($adminRoutes);

it('allows an admin', function (string $path): void {
    $this->actingAs(userWithRole('admin'))->get($path)->assertOk();
})->with($adminRoutes);

it('allows a super admin', function (string $path): void {
    $this->actingAs(userWithRole('super_admin'))->get($path)->assertOk();
})->with($adminRoutes);

it('forbids a customer from editing a ruleset', function (): void {
    $draft = AuctionRuleset::factory()->create();

    $this->actingAs(userWithRole('customer'))
        ->get(route('admin.rulesets.edit', $draft))
        ->assertForbidden();
});

it('does not show admin navigation to a customer', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Auction rulesets')
        ->assertDontSee(route('admin.settings'));
});

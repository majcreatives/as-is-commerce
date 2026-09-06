<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

/*
 * What the administration area must not become.
 *
 * Stage 14 adds a control surface over domain services that already exist. The
 * risk it introduces is a second implementation of a rule -- an admin screen
 * that asserts an outcome instead of asking the domain for it. These tests are
 * the fence around that.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();
});

// --------------------------------------------------------------- Permissions

it('seeds every permission this stage introduces', function (string $permission): void {
    expect(Permission::where('name', $permission)->exists())->toBeTrue();
})->with([
    'admin.dashboard.view',
    'exceptions.view',
    'customers.view',
    'audit.view',
]);

it('grants the new permissions to both administrative roles', function (string $role): void {
    $user = userWithRole($role);

    foreach (['admin.dashboard.view', 'exceptions.view', 'customers.view', 'audit.view'] as $permission) {
        expect($user->can($permission))->toBeTrue("{$role} should hold {$permission}");
    }
})->with(['admin', 'super_admin']);

it('gives a customer none of them', function (): void {
    $customer = userWithRole('customer');

    foreach (['admin.dashboard.view', 'exceptions.view', 'customers.view', 'audit.view'] as $permission) {
        expect($customer->can($permission))->toBeFalse("a customer must not hold {$permission}");
    }
});

// --------------------------------------------------------------- Routes

it('gates every new admin route behind a permission as well as a role', function (string $name): void {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull("route {$name} should exist");

    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain('auth')
        ->and(collect($middleware)->contains(fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'role:')))
        ->toBeTrue("route {$name} should carry a role check")
        ->and(collect($middleware)->contains(fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'can:')))
        ->toBeTrue("route {$name} should carry a permission check");
})->with([
    'admin.dashboard',
    'admin.exceptions',
    'admin.search',
    'admin.customers.index',
    'admin.customers.show',
    'admin.payments',
    'admin.audit',
]);

it('refuses every new admin route to a signed-in customer', function (string $name): void {
    $customer = userWithRole('customer');

    $url = $name === 'admin.customers.show' ? route($name, $customer) : route($name);

    $this->actingAs($customer)->get($url)->assertForbidden();
})->with([
    'admin.dashboard',
    'admin.exceptions',
    'admin.search',
    'admin.customers.index',
    'admin.customers.show',
    'admin.payments',
    'admin.audit',
]);

it('refuses every new admin route to a guest', function (string $name): void {
    $url = $name === 'admin.customers.show'
        ? route($name, User::factory()->create())
        : route($name);

    $this->get($url)->assertRedirect(route('login'));
})->with([
    'admin.dashboard',
    'admin.exceptions',
    'admin.search',
    'admin.customers.index',
    'admin.customers.show',
    'admin.payments',
    'admin.audit',
]);

// --------------------------------------------------------------- No second implementation

it('adds no permission that would let staff assert an outcome', function (): void {
    // Nothing named for marking, forcing or overriding. If a capability like
    // that is ever wanted, what is actually wanted is a way to re-run
    // verification against the provider.
    foreach (PermissionSeeder::PERMISSIONS as $permission) {
        expect($permission)
            ->not->toContain('mark_paid')
            ->not->toContain('force')
            ->not->toContain('override');
    }
});

it('has no admin screen offering to mark an order paid', function (): void {
    $blades = collect(File::allFiles(resource_path('views/livewire/admin')))
        ->map(fn ($file): string => $file->getPathname());

    expect($blades)->not->toBeEmpty();

    foreach ($blades as $path) {
        $contents = File::get($path);

        foreach (['Mark paid', 'Mark as paid', 'markPaid', 'forcePaid'] as $forbidden) {
            expect(str_contains($contents, $forbidden))
                ->toBeFalse(basename($path).' must not offer '.$forbidden);
        }
    }
});

it('offers no bulk action over financial or audit records', function (): void {
    $blades = collect(File::allFiles(resource_path('views/livewire/admin')))
        ->map(fn ($file): string => $file->getPathname());

    foreach ($blades as $path) {
        $contents = File::get($path);

        foreach (['Delete selected', 'Bulk refund', 'Bulk delete', 'Purge'] as $forbidden) {
            expect(str_contains($contents, $forbidden))
                ->toBeFalse(basename($path).' must not offer '.$forbidden);
        }
    }
});

// --------------------------------------------------------------- Navigation

it('hides every navigation group an administrator holds no permission for', function (): void {
    // Somebody who may only look at orders. The grouped navigation must show
    // Commerce and nothing else -- a heading over an empty group would be a
    // list of doors they cannot open.
    $this->actingAs(staffWith(['orders.view']));

    $rendered = Blade::render('<x-admin.nav />');

    expect($rendered)->toContain('Commerce')
        ->and($rendered)->toContain('Orders')
        ->not->toContain('Operations')
        ->not->toContain('Money')
        ->not->toContain('Configuration');
});

it('shows an administrator every group', function (): void {
    $this->actingAs(userWithRole('admin'));

    $rendered = Blade::render('<x-admin.nav />');

    foreach (['Operations', 'Commerce', 'Money', 'Catalogue', 'Configuration'] as $heading) {
        expect($rendered)->toContain($heading);
    }
});

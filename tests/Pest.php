<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Seeds the roles the application depends on.
 *
 * Reference data, not fixtures: these roles exist in every environment.
 */
function seedRoles(): void
{
    app(RoleSeeder::class)->run();
}

/**
 * Seeds roles and the permissions attached to them.
 */
function seedPermissions(): void
{
    seedRoles();
    app(PermissionSeeder::class)->run();
}

/**
 * Seeds the application's settings definitions.
 */
function seedSettings(): void
{
    app(SettingsSeeder::class)->run();
}

/**
 * A user holding the given role, with permissions already seeded.
 */
function userWithRole(string $role): User
{
    seedPermissions();

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

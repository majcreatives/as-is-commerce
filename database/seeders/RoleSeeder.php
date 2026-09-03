<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Reference data: the roles the application itself depends on.
 *
 * Safe to run repeatedly. Additional roles from the target admin model
 * (finance, auction manager, fulfilment, and so on) are added by the stages
 * that introduce the permissions they need.
 */
class RoleSeeder extends Seeder
{
    /** @var list<string> */
    public const ROLES = ['customer', 'admin', 'super_admin'];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}

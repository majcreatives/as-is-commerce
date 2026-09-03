<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reference data: the permissions this stage introduces, and the roles that
 * hold them.
 *
 * Authorization is expressed as permissions rather than role-name checks, so
 * a later stage can introduce a Finance Admin or Auction Manager by granting
 * a subset of these without editing a single call site.
 *
 * Safe to run repeatedly.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Permissions owned by the settings and rules engine.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'settings.view',
        'settings.update',

        'auction_rulesets.view',
        'auction_rulesets.create',
        'auction_rulesets.update',
        'auction_rulesets.activate',
        'auction_rulesets.archive',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Both administrative roles receive the full set for this stage. They
        // diverge later, when stages introduce permissions that only a
        // super admin should hold.
        foreach (['admin', 'super_admin'] as $roleName) {
            Role::findOrCreate($roleName, 'web')->givePermissionTo(self::PERMISSIONS);
        }

        // Customers hold none of these, and are never granted them implicitly.

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

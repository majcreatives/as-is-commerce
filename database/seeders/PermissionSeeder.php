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

        'credits.view',
        'credits.adjust',
        'wallets.view',
        'wallets.inspect',
        'wallets.reconcile',
        'cash.view',

        'credit_packages.view',
        'credit_packages.create',
        'credit_packages.update',
        'credit_packages.activate',
        'credit_packages.archive',
        'credit_purchases.view',
        'credit_purchases.inspect',
        'payment_events.view',

        'products.view',
        'products.create',
        'products.update',
        'products.activate',
        'products.archive',

        'categories.view',
        'categories.create',
        'categories.update',
        'categories.activate',
        'categories.archive',

        'brands.view',
        'brands.create',
        'brands.update',
        'brands.activate',
        'brands.archive',

        'inventory.view',
        'inventory.adjust',
    ];

    /**
     * Permissions a customer holds, which govern their own wallet only.
     *
     * Seeing your own balance and history is not an administrative act, so
     * these are granted to every customer.
     *
     * The distinction that matters: `wallets.view` means "see my wallet";
     * `wallets.inspect` means "see anyone's wallet" and is staff-only. Gating
     * an admin screen on `wallets.view` would open it to every customer, so
     * the two are deliberately separate permissions rather than one.
     *
     * @var list<string>
     */
    public const CUSTOMER_PERMISSIONS = [
        'credits.view',
        'wallets.view',
    ];

    /**
     * Permissions only staff hold. No customer is ever granted these.
     *
     * @return list<string>
     */
    public static function staffPermissions(): array
    {
        return array_values(array_diff(self::PERMISSIONS, self::CUSTOMER_PERMISSIONS));
    }

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

        Role::findOrCreate('customer', 'web')->givePermissionTo(self::CUSTOMER_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

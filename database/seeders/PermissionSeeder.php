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

        // Auction administration. Staff-only, all of them: `auctions.view`
        // means "see every auction, including drafts", which is not what a
        // customer browsing the public listing is doing.
        'auctions.view',
        'auctions.create',
        'auctions.update',
        'auctions.publish',
        'auctions.cancel',
        'auctions.relist',

        // Seeing anyone's bids. The customer equivalent -- placing a bid, and
        // seeing your own -- is `bids.place` below.
        'bids.inspect',
        'bids.place',

        // Order administration. Staff-only: `orders.view` means "see every
        // order", which is not what a customer looking at their own history is
        // doing -- that is `orders.view_own` below.
        'orders.view',
        'orders.manage',
        'order_payments.view',

        // Buying. Held by every customer.
        'orders.view_own',
        'checkout.create',
        'addresses.manage',

        // Seeing what the platform told people, and what failed to reach
        // them. Staff-only and read-only: there is no permission to edit,
        // resend or delete a notification, because no such capability exists.
        'notifications.inspect',

        // Returning money. Split deliberately rather than granted as one
        // capability: seeing that a customer is owed something, deciding to
        // give it back, and sending it to the provider are three different
        // acts, and a narrower finance role should be able to hold some of
        // them without holding the rest.
        //
        // No customer holds any of these. A refund is never something the
        // person being refunded sets in motion.
        'refunds.view',
        'refunds.request',
        'refunds.process',
        'refunds.retry',
        // Seeing where our records and the provider's disagree. Separate from
        // `refunds.view` because it is a different kind of question -- not
        // "what did we refund" but "is what we believe actually true".
        'refunds.inspect',

        // Moving physical packages. Split the way the work is actually split
        // in a warehouse: seeing the queue, packing, sending something out,
        // confirming it arrived, and stopping one are different acts done by
        // different people, and a packer should not need the authority to
        // declare an order complete.
        //
        // No customer holds any of these. A customer cannot advance their own
        // delivery, however much they would like to.
        'deliveries.view',
        'deliveries.update',
        'deliveries.dispatch',
        'deliveries.complete',
        'deliveries.retry',
        'deliveries.cancel',
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

        // Bidding is what a customer account is for. Kept deliberately apart
        // from `bids.inspect`, which is seeing everyone's bids and is staff
        // only -- the same distinction, and for the same reason, as
        // `wallets.view` against `wallets.inspect`.
        'bids.place',

        // Buying, and seeing what you bought. The staff equivalents --
        // `orders.view` for everyone's orders, `orders.manage` for moving one
        // along -- are deliberately different permissions, for the same
        // reason.
        'checkout.create',
        'orders.view_own',

        // Managing your own address book. Not an administrative act -- it is
        // saying where you live -- and deliberately not paired with any
        // `deliveries.*` permission: a customer may say where a package
        // should go and may never say where it has got to.
        'addresses.manage',
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

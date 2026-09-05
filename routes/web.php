<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Orders\CheckoutCallbackController;
use App\Http\Controllers\Payments\PaystackCallbackController;
use App\Http\Controllers\Payments\PaystackWebhookController;
use App\Livewire\Account\Dashboard;
use App\Livewire\Account\ReferralDashboard;
use App\Livewire\Admin\Auctions\AuctionDetail as AdminAuctionDetail;
use App\Livewire\Admin\Auctions\AuctionManager;
use App\Livewire\Admin\Catalog\InventoryManager;
use App\Livewire\Admin\Catalog\ProductManager;
use App\Livewire\Admin\Catalog\TaxonomyManager;
use App\Livewire\Admin\Delivery\FulfilmentQueue;
use App\Livewire\Admin\Notifications\NotificationIndex;
use App\Livewire\Admin\Orders\OrderDetail as AdminOrderDetail;
use App\Livewire\Admin\Orders\OrderManager;
use App\Livewire\Admin\Payments\PackageManager;
use App\Livewire\Admin\Payments\PurchaseIndex;
use App\Livewire\Admin\Payments\WebhookEventIndex;
use App\Livewire\Admin\Referrals\ReferralQueue;
use App\Livewire\Admin\Refunds\RefundQueue;
use App\Livewire\Admin\Rulesets\RulesetForm;
use App\Livewire\Admin\Rulesets\RulesetIndex;
use App\Livewire\Admin\Settings\ManageSettings;
use App\Livewire\Admin\Wallets\WalletDetail;
use App\Livewire\Admin\Wallets\WalletIndex;
use App\Livewire\Auctions\AuctionIndex;
use App\Livewire\Auctions\AuctionRoom;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Catalog\ProductCatalog;
use App\Livewire\Catalog\ProductDetail;
use App\Livewire\Checkout\CheckoutPage;
use App\Livewire\Credits\CreditPackages;
use App\Livewire\Credits\PurchaseHistory;
use App\Livewire\Delivery\AddressBookPage;
use App\Livewire\Delivery\OrderTracking;
use App\Livewire\Marketplace\Home;
use App\Livewire\Notifications\NotificationCentre;
use App\Livewire\Orders\OrderDetail;
use App\Livewire\Orders\OrderIndex;
use App\Livewire\Wallet\WalletOverview;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operations
|--------------------------------------------------------------------------
*/

Route::get('/health', HealthController::class)->name('health');

/*
|--------------------------------------------------------------------------
| Payment provider webhooks
|--------------------------------------------------------------------------
|
| Public and unauthenticated: a payment provider cannot log in. Each request
| is authenticated by its HMAC signature instead, verified before anything
| is stored or acted on. CSRF is exempted in bootstrap/app.php for the same
| reason.
|
*/

Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->name('webhooks.paystack');

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', Home::class)->name('home');
// Auctions. Both routes resolve only publicly visible auctions, so a draft
// 404s rather than existing at a guessable URL.
Route::get('/auctions', AuctionIndex::class)->name('auctions.index');
Route::get('/auctions/{auction}', AuctionRoom::class)->name('auctions.show');
Route::view('/how-it-works', 'pages.how-it-works')->name('how-it-works');

// The catalog. Both routes resolve only publicly visible products, so a
// draft or archived listing 404s rather than existing at a guessable URL.
Route::get('/products', ProductCatalog::class)->name('products.index');
Route::get('/products/{slug}', ProductDetail::class)->name('products.show');

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function (): void {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::view('/profile', 'pages.profile')->name('profile.edit');
    Route::post('/logout', LogoutController::class)->name('logout');

    // A customer's own wallets. Read-only: buying credits needs payment
    // processing, which belongs to a later stage.
    Route::get('/wallet', WalletOverview::class)
        ->middleware('can:wallets.view')
        ->name('wallet');

    // Buying credits. The browser sends a package, never a price.
    Route::get('/credits', CreditPackages::class)->name('credits.packages');
    Route::get('/credits/history', PurchaseHistory::class)->name('credits.history');

    // Where the provider returns the customer's browser. Not proof of payment.
    Route::get('/credits/callback', PaystackCallbackController::class)->name('credits.callback');

    /*
     | Checkout and orders.
     |
     | Every one of these resolves an order the signed-in user owns; an order
     | number in a URL is not a capability to view or pay for somebody else's
     | purchase. The browser never sends an amount to any of them.
     */
    // Declared before the {order} route, which would otherwise capture
    // "callback" as an order number and 404 every returning payer.
    //
    // Where Paystack returns the browser after paying for a product. Verified
    // server-side before it says anything, exactly like the credits callback.
    Route::get('/checkout/callback', CheckoutCallbackController::class)->name('checkout.callback');

    Route::get('/checkout/{order}', CheckoutPage::class)->name('checkout.show');

    Route::get('/orders', OrderIndex::class)->name('orders.index');
    Route::get('/orders/{order}', OrderDetail::class)->name('orders.show');

    // Where the package is. Ownership is checked in the component, not by the
    // route: an order number in a URL is not a capability to watch somebody
    // else's delivery.
    Route::get('/orders/{order}/tracking', OrderTracking::class)->name('orders.tracking');

    // A customer's own addresses. Saying where you live is not an
    // administrative act, so this needs no staff permission -- and holding it
    // grants no ability to move a package.
    Route::get('/addresses', AddressBookPage::class)->name('addresses.index');

    // A customer's own referrals. Scoped to the signed-in user inside the
    // query, so there is no path to anybody else's -- and nothing on it can
    // issue a credit.
    Route::get('/referrals', ReferralDashboard::class)->name('referrals.index');

    // A customer's own notifications. Scoped to the signed-in user inside the
    // query, so there is no path to anybody else's.
    Route::get('/notifications', NotificationCentre::class)->name('notifications.index');
});

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
|
| Role checks live in middleware rather than in controller bodies so
| authorization stays declarative and auditable in one place.
|
*/

Route::middleware(['auth', 'role:admin|super_admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::view('/', 'pages.admin.dashboard')->name('dashboard');

        // Individual capabilities are gated by permission, not by role, so a
        // narrower administrative role can be introduced later without
        // touching these routes.
        Route::get('/settings', ManageSettings::class)
            ->middleware('can:settings.view')
            ->name('settings');

        Route::get('/rulesets', RulesetIndex::class)
            ->middleware('can:auction_rulesets.view')
            ->name('rulesets.index');

        Route::get('/rulesets/create', RulesetForm::class)
            ->middleware('can:auction_rulesets.create')
            ->name('rulesets.create');

        Route::get('/rulesets/{ruleset}/edit', RulesetForm::class)
            ->middleware('can:auction_rulesets.update')
            ->name('rulesets.edit');

        // Gated on wallets.inspect, not wallets.view: every customer holds
        // wallets.view for their own wallet, so it would not restrict this.
        Route::get('/wallets', WalletIndex::class)
            ->middleware('can:wallets.inspect')
            ->name('wallets.index');

        Route::get('/wallets/{user}', WalletDetail::class)
            ->middleware('can:wallets.inspect')
            ->name('wallets.show');

        Route::get('/credit-packages', PackageManager::class)
            ->middleware('can:credit_packages.view')
            ->name('credit-packages');

        Route::get('/credit-purchases', PurchaseIndex::class)
            ->middleware('can:credit_purchases.view')
            ->name('credit-purchases');

        Route::get('/payment-events', WebhookEventIndex::class)
            ->middleware('can:payment_events.view')
            ->name('payment-events');

        Route::get('/products', ProductManager::class)
            ->middleware('can:products.view')
            ->name('products');

        Route::get('/taxonomy', TaxonomyManager::class)
            ->middleware('can:categories.view')
            ->name('taxonomy');

        Route::get('/inventory', InventoryManager::class)
            ->middleware('can:inventory.view')
            ->name('inventory');

        // Gated on auctions.view, which is staff-only. The public auction
        // pages need no permission at all -- browsing an auction and
        // administering one are different things.
        Route::get('/auctions', AuctionManager::class)
            ->middleware('can:auctions.view')
            ->name('auctions.index');

        Route::get('/auctions/{auction}', AdminAuctionDetail::class)
            ->middleware('can:auctions.view')
            ->name('auctions.show');

        // Gated on orders.view, which is staff-only. A customer's own order
        // history is `orders.view_own` and lives on entirely separate routes.
        Route::get('/orders', OrderManager::class)
            ->middleware('can:orders.view')
            ->name('orders.index');

        Route::get('/orders/{order}', AdminOrderDetail::class)
            ->middleware('can:orders.view')
            ->name('orders.show');

        // Read-only delivery inspection. Staff-only, and distinct from a
        // customer's own notification list, which needs no permission at all.
        Route::get('/notifications', NotificationIndex::class)
            ->middleware('can:notifications.inspect')
            ->name('notifications');

        // Returning money. Gated on refunds.view, which no customer holds --
        // a refund is never something the person being refunded sets in
        // motion. The narrower capabilities (requesting, sending, retrying)
        // are checked again inside the component, so holding the view
        // permission alone shows the queue without offering the actions.
        Route::get('/refunds', RefundQueue::class)
            ->middleware('can:refunds.view')
            ->name('refunds');

        // The warehouse's own screen. Gated on deliveries.view, which no
        // customer holds -- the narrower capabilities (packing, dispatching,
        // completing, retrying, cancelling) are checked again inside the
        // component, so holding the view permission alone shows the queue
        // without offering the actions.
        Route::get('/fulfilment', FulfilmentQueue::class)
            ->middleware('can:deliveries.view')
            ->name('fulfilment');

        // The referral programme. Gated on referrals.view, which no customer
        // holds; acting on a referral needs referrals.manage, checked again
        // inside the component.
        Route::get('/referrals', ReferralQueue::class)
            ->middleware('can:referrals.view')
            ->name('referrals');
    });

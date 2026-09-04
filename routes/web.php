<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Catalog\ProductDetailController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Payments\PaystackCallbackController;
use App\Http\Controllers\Payments\PaystackWebhookController;
use App\Livewire\Admin\Auctions\AuctionDetail as AdminAuctionDetail;
use App\Livewire\Admin\Auctions\AuctionManager;
use App\Livewire\Admin\Catalog\InventoryManager;
use App\Livewire\Admin\Catalog\ProductManager;
use App\Livewire\Admin\Catalog\TaxonomyManager;
use App\Livewire\Admin\Payments\PackageManager;
use App\Livewire\Admin\Payments\PurchaseIndex;
use App\Livewire\Admin\Payments\WebhookEventIndex;
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
use App\Livewire\Credits\CreditPackages;
use App\Livewire\Credits\PurchaseHistory;
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

Route::view('/', 'pages.home')->name('home');
// Auctions. Both routes resolve only publicly visible auctions, so a draft
// 404s rather than existing at a guessable URL.
Route::get('/auctions', AuctionIndex::class)->name('auctions.index');
Route::get('/auctions/{auction}', AuctionRoom::class)->name('auctions.show');
Route::view('/how-it-works', 'pages.how-it-works')->name('how-it-works');

// The catalog. Both routes resolve only publicly visible products, so a
// draft or archived listing 404s rather than existing at a guessable URL.
Route::get('/products', ProductCatalog::class)->name('products.index');
Route::get('/products/{slug}', ProductDetailController::class)->name('products.show');

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
    Route::view('/dashboard', 'pages.dashboard')->name('dashboard');
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
    });

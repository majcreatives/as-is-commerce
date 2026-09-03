<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\HealthController;
use App\Livewire\Admin\Rulesets\RulesetForm;
use App\Livewire\Admin\Rulesets\RulesetIndex;
use App\Livewire\Admin\Settings\ManageSettings;
use App\Livewire\Admin\Wallets\WalletDetail;
use App\Livewire\Admin\Wallets\WalletIndex;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
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
| Public
|--------------------------------------------------------------------------
*/

Route::view('/', 'pages.home')->name('home');
Route::view('/auctions', 'pages.auctions')->name('auctions.index');
Route::view('/how-it-works', 'pages.how-it-works')->name('how-it-works');

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
    });

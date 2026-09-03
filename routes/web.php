<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\HealthController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
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
    });

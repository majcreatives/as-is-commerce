<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Phone\GhanaPhoneNumberNormalizer;
use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Support\UnconfiguredOtpChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Phone handling is swappable: a multi-country implementation can
        // replace this binding without touching any call site.
        $this->app->singleton(PhoneNumberNormalizer::class, GhanaPhoneNumberNormalizer::class);

        // No SMS provider is integrated. The default binding throws rather
        // than pretending a code was delivered.
        $this->app->bind(OtpChannel::class, UnconfiguredOtpChannel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surfaces lazy loading and mass-assignment mistakes during
        // development, where they are cheap to fix. Left off in production so
        // a missed eager-load degrades performance rather than erroring.
        Model::shouldBeStrict(! $this->app->isProduction());

        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers());

        // Validation messages should name the field the user recognises.
        Validator::excludeUnvalidatedArrayKeys();
    }
}

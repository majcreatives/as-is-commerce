<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Settings\SettingsRepository;
use App\Domain\Shared\Phone\GhanaPhoneNumberNormalizer;
use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Support\UnconfiguredOtpChannel;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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

        // A singleton so the per-request settings snapshot is shared across
        // every caller in the request rather than rebuilt per resolution.
        $this->app->singleton(
            SettingsRepository::class,
            fn ($app): SettingsRepository => new SettingsRepository($app->make(CacheRepository::class)),
        );
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

        // A super admin passes every permission check, including permissions
        // introduced by later stages that have not been seeded onto the role
        // yet. Returning null (rather than false) for everyone else leaves the
        // normal permission checks to decide, instead of short-circuiting them.
        Gate::before(
            fn (User $user, string $ability): ?bool => $user->hasRole('super_admin') ? true : null
        );
    }
}

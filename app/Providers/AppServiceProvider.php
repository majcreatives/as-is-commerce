<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auction\Contracts\SettlementHandoff;
use App\Domain\Delivery\Services\OrderFulfilmentHandoff;
use App\Domain\Orders\Contracts\FulfilmentHandoff;
use App\Domain\Orders\Services\AuctionSettlementHandoff;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Paystack\PaystackGateway;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shared\Phone\GhanaPhoneNumberNormalizer;
use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Support\UnconfiguredOtpChannel;
use App\Listeners\AuctionBroadcastSubscriber;
use App\Listeners\NotificationSubscriber;
use App\Listeners\ReferralSubscriber;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
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

        // How a closing auction hands its winner over to be paid. An
        // interface because the checkout layer already depends on the auction
        // layer, and a direct call back would tie the two together in both
        // directions.
        $this->app->bind(SettlementHandoff::class, AuctionSettlementHandoff::class);

        // And the same shape one layer further on: a paid order hands itself
        // to the delivery domain through an interface, so orders never has to
        // know that deliveries exist.
        $this->app->bind(FulfilmentHandoff::class, OrderFulfilmentHandoff::class);

        // No SMS provider is integrated. The default binding throws rather
        // than pretending a code was delivered.
        $this->app->bind(OtpChannel::class, UnconfiguredOtpChannel::class);

        // A singleton so the per-request settings snapshot is shared across
        // every caller in the request rather than rebuilt per resolution.
        $this->app->singleton(
            SettingsRepository::class,
            fn ($app): SettingsRepository => new SettingsRepository($app->make(CacheRepository::class)),
        );

        // The payment provider sits behind an interface so the credit and
        // wallet domains never name Paystack. Swapping provider later is a
        // change to this binding and one adapter class.
        $this->app->bind(PaymentGateway::class, fn ($app): PaystackGateway => new PaystackGateway(
            http: $app->make(HttpFactory::class),
            secretKey: config('paystack.secret_key'),
            baseUrl: (string) config('paystack.base_url'),
            currency: (string) config('paystack.currency'),
            timeout: (int) config('paystack.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every notification the platform sends, in one place. Registered as
        // a subscriber rather than a listener per event so the wording, the
        // recipients and the idempotency keys can be read against each other.
        Event::subscribe(NotificationSubscriber::class);

        // The referral programme listens to the same order events. A separate
        // subscriber, so the orders domain knows nothing about referrals and
        // removing the programme would mean deleting one file.
        Event::subscribe(ReferralSubscriber::class);

        // The real-time transport listens to the same auction events and
        // reduces each to a public payload. A third subscriber rather than a
        // flag on the events themselves: broadcasting must never be able to
        // throw into a committed bid, and this one is guarded exactly as the
        // notification subscriber is. Deleting it would remove the live
        // updates and change no business rule.
        Event::subscribe(AuctionBroadcastSubscriber::class);

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

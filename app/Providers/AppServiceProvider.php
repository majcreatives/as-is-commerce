<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auction\Contracts\AuctionLossCompensation;
use App\Domain\Auction\Contracts\SettlementHandoff;
use App\Domain\Delivery\Services\OrderFulfilmentHandoff;
use App\Domain\Orders\Contracts\FulfilmentHandoff;
use App\Domain\Orders\Services\AuctionSettlementHandoff;
use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Paystack\PaystackGateway;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shared\Phone\GhanaPhoneNumberNormalizer;
use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Domain\StoreWallet\Services\AuctionLossCompensator;
use App\Domain\User\Arkesel\ArkeselSmsGateway;
use App\Domain\User\Contracts\OtpChannel;
use App\Domain\User\Contracts\SmsGateway;
use App\Domain\User\Support\MailOtpChannel;
use App\Listeners\AuctionBroadcastSubscriber;
use App\Listeners\NotificationSubscriber;
use App\Listeners\ReferralSubscriber;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // How an ending auction gives its losing bidders their consumed
        // credits' value back. An interface for the same reason as
        // SettlementHandoff: the Store Wallet domain reads bids to value what
        // was consumed, and a direct call back would couple the two in both
        // directions.
        $this->app->bind(AuctionLossCompensation::class, AuctionLossCompensator::class);

        // How a one-time code travels by email. Held behind the interface so a
        // failing transport can be substituted where a test needs to prove the
        // delivery-failure guarantee.
        $this->app->bind(OtpChannel::class, MailOtpChannel::class);

        // How a one-time code travels by text message. The provider is bound
        // behind its own interface, so replacing Arkesel with another Ghanaian
        // gateway is a new adapter and this one line, with no call site
        // anywhere in the recovery flow changing.
        $this->app->bind(SmsGateway::class, fn ($app): SmsGateway => new ArkeselSmsGateway(
            http: $app->make(HttpFactory::class),
            apiKey: config('sms.api_key'),
            senderId: config('sms.sender_id'),
            baseUrl: (string) config('sms.base_url'),
            timeout: (int) config('sms.timeout'),
        ));

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
        // introduced by later stages that are not yet seeded onto the role yet.
        // Returning null (rather than false) for everyone else leaves the
        // normal permission checks to decide, instead of short-circuiting them.
        Gate::before(
            fn (User $user, string $ability): ?bool => $user->hasRole('super_admin') ? true : null
        );

        // The company content screen has three tabs behind three separate
        // permission sets, and holding any one of them is a legitimate reason
        // to open it.
        //
        // This exists as a Gate ability because `can:` cannot express that.
        // Illuminate\Auth\Middleware\Authorize::handle() takes the first segment
        // as the ability and passes the REST to the gate as model arguments, so
        // `can:partners.view,success_stories.view` authorizes partners.view alone
        // and treats success_stories.view as a class name to load. It is an AND
        // dressed as an OR, and reading it the other way round is easy.
        Gate::define(
            'content.view',
            fn (User $user): bool => $user->can('partners.view')
                || $user->can('success_stories.view')
                || $user->can('posts.view')
        );

        $this->registerRateLimiters();
    }

    /**
     * Throttles for the unauthenticated edges of the site.
     *
     * These sit in front of the pages, not in front of the work. The
     * operations that actually matter -- checking a password, presenting a
     * reset code, issuing a code -- are throttled inside the Livewire
     * components that perform them, because a limit on a page load does
     * nothing to stop an action submitted over Livewire's own endpoint.
     *
     * Nothing here is keyed by account, and nothing here is the only defence
     * for anything. The four limits that stop an account being guessed or
     * created in bulk live in the Livewire components that perform the work:
     * five wrong passwords a minute by identifier and address, three reset
     * codes a minute by identifier, five wrong reset codes an hour by account
     * and address, twenty accounts a day by address, and sixty newsletter
     * confirmation or unsubscribe clicks an hour by address.
     */
    private function registerRateLimiters(): void
    {
        // The limiter's name already namespaces the key, so by() carries only
        // what makes the bucket distinct. ThrottleRequests derives the cache key
        // from the name and this value, and prefixes nothing itself.
        //
        // The unauthenticated forms. These pages hold no data and no secrets,
        // so the limit is here to slow down scripted enumeration and to keep
        // the login page from being scraped, not to protect anything. The
        // ceiling is deliberately loose: a limit low enough to matter to an
        // attacker is also low enough to lock out somebody who mistypes a
        // password twice, and none of these pages are where that matters.
        RateLimiter::for('auth-pages', fn (): Limit => Limit::perMinute(30)
            ->by(request()->ip()));

        // The two Paystack return routes. A payer arrives here once, having
        // come back from Paystack, so the ceiling is loose by the same
        // reasoning. Throttling these cannot endanger a payment: the order and
        // the credit purchase are both fulfilled from the webhook, which is
        // deliberately not throttled, so a payer who is refused here is still
        // fulfilled. The server-side Paystack verification is what makes the
        // route safe to expose at all, and that has not changed.
        RateLimiter::for('payment-callbacks', fn (): Limit => Limit::perMinute(30)
            ->by(request()->ip()));

        // The two newsletter email links, confirmation and unsubscribe.
        //
        // TIGHTER THAN auth-pages, and for a different reason: those pages have
        // nothing on them, whereas these act on a record. The tokens are 64
        // random characters so they cannot be guessed in any practical number of
        // requests, but this is the one place where a guessed or harvested token
        // has a real effect -- confirming an address somebody did not own, or
        // silently removing somebody who did. Sixty an hour is generous for a
        // person clicking one link they received, and slow enough that a run
        // through tokens is pointless.
        RateLimiter::for('newsletter-links', fn (): Limit => Limit::perHour(60)
            ->by(request()->ip()));
    }
}

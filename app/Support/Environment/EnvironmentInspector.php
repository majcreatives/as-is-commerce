<?php

declare(strict_types=1);

namespace App\Support\Environment;

use App\Models\Notification;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads the running configuration and says whether this installation is fit to
 * be public.
 *
 * WHY THIS EXISTS RATHER THAN A DOCUMENT. The environment is assembled by a
 * human, from `.env.example`, on a server nobody can see from a laptop. The
 * file that gets copied ships `APP_DEBUG=true`, `MAIL_MAILER=log` and
 * `SESSION_SECURE_COOKIE=false`, because it is written for a developer. Every
 * production value that differs from those defaults is therefore a value
 * somebody remembered to change, and a value somebody remembered is a value
 * somebody can forget.
 *
 * Every check below corresponds to a way the site fails that is invisible from
 * the outside: a debug page that leaks a stack trace, a receipt recorded as sent
 * and never delivered, a session cookie on plain HTTP, a checkout that cannot
 * verify a payment. None of them announces itself. This turns them into
 * something a deploy cannot finish without.
 *
 * IT READS CONFIGURATION, NOT INTENT, AND CHANGES NOTHING. No migration, no
 * write, no repair. The same rule as every other reconciler in this codebase: a
 * check that quietly fixed what it found would be making the decision a person
 * is being asked to make, and would hide the fact that it had been made.
 *
 * `exposed` is the distinction everything turns on. Local and testing get
 * developer defaults, deliberately: a developer working with the log mailer has
 * not misconfigured anything. Everything reachable by a customer or a stranger
 * gets the strict reading, which includes staging, because staging is on the
 * public internet and its errors are the ones a real customer could find.
 */
final class EnvironmentInspector
{
    /**
     * Transports that accept a message and deliver it to nobody.
     *
     * `log` writes the rendered message to a log file and returns normally;
     * `array` holds it in memory for the life of the request. Neither raises an
     * exception, which is exactly why they are dangerous: the notification
     * dispatcher writes `mail_status = 'sent'` on the strength of that
     * non-exception, so every receipt, every referral reward and every password
     * reset would be recorded as delivered when nothing left the server.
     */
    private const NON_DELIVERING_MAILERS = ['log', 'array'];

    /**
     * Drivers that forget everything between requests.
     *
     * Correct for a test, useless in production: a login, a cart or a credit
     * balance held in per-request memory is a customer who appears to have lost
     * their money.
     */
    private const EPHEMERAL_DRIVERS = ['array'];

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * Whether this installation can be reached by somebody who is not us.
     */
    public function isExposed(): bool
    {
        return ! in_array((string) $this->config->get('app.env'), ['local', 'testing'], true);
    }

    /**
     * @return list<EnvironmentFinding>
     */
    public function inspect(): array
    {
        $exposed = $this->isExposed();

        return [
            ...$this->applicationKey($exposed),
            ...$this->debugMode($exposed),
            ...$this->mailTransport($exposed),
            ...$this->falseSentNotifications(),
            ...$this->secureCookie($exposed),
            ...$this->durableState($exposed),
            ...$this->payments($exposed),
            ...$this->logging($exposed),
            ...$this->queuedMail(),
            ...$this->supportContact($exposed),
        ];
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function applicationKey(bool $exposed): array
    {
        $key = (string) $this->config->get('app.key');

        if ($key === '') {
            return [EnvironmentFinding::blocker(
                'app.key',
                'No application key is set.',
                'Sessions cannot be encrypted, signed cookies are forgeable, and encrypted model '
                .'attributes cannot be read. Nothing should be served at all.',
            )];
        }

        return [EnvironmentFinding::ok('app.key', 'Set.')];
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function debugMode(bool $exposed): array
    {
        if (! $this->config->get('app.debug')) {
            return [EnvironmentFinding::ok('app.debug', 'Off, so errors render without stack traces.')];
        }

        if (! $exposed) {
            return [EnvironmentFinding::warning(
                'app.debug',
                'On.',
                'Correct for local work. Must be off anywhere reachable from the internet.',
            )];
        }

        return [EnvironmentFinding::blocker(
            'app.debug',
            'On in an environment that is reachable from the internet.',
            'Any unhandled error returns a full stack trace with file paths, framework internals '
            .'and often a dump of configuration values to whoever triggered it. On a payments site '
            .'that is an invitation, and it is the single most common way a Laravel deployment is '
            .'exploited.',
        )];
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function mailTransport(bool $exposed): array
    {
        $mailer = (string) $this->config->get('mail.default');

        if (! in_array($mailer, self::NON_DELIVERING_MAILERS, true)) {
            return [EnvironmentFinding::ok('mail.default', "The {$mailer} transport delivers mail.")];
        }

        if (! $exposed) {
            return [EnvironmentFinding::warning(
                'mail.default',
                "The {$mailer} transport accepts messages and delivers none.",
                'Correct for local work. Anywhere public, every customer email goes nowhere.',
            )];
        }

        return [EnvironmentFinding::blocker(
            'mail.default',
            "The {$mailer} transport accepts messages and delivers none.",
            'This is worse than broken mail, because nothing reports it as broken. The dispatcher '
            .'treats a non-exception as success and writes mail_status=sent, so order receipts, '
            .'referral rewards and password resets are all recorded as delivered to an address '
            .'nobody was ever contacted at. The site would claim to have emailed a customer while '
            .'the message sits in a log file.',
        )];
    }

    /**
     * Notifications the platform believes it delivered by a transport that
     * delivers nothing.
     *
     * Only provable while the transport is still non-delivering, and stated as
     * such: once the mailer is corrected, rows written while it was wrong
     * remain indistinguishable from genuine deliveries, because nothing recorded
     * which transport was configured at the time. That is a limit of the design
     * rather than of this check, and inventing a way to guess would be worse
     * than saying so.
     *
     * @return list<EnvironmentFinding>
     */
    private function falseSentNotifications(): array
    {
        if (! in_array((string) $this->config->get('mail.default'), self::NON_DELIVERING_MAILERS, true)) {
            return [];
        }

        try {
            $count = Notification::query()->where('mail_status', 'sent')->count();
        } catch (Throwable) {
            // No database yet, or no table. Not this check's problem to report,
            // and a readiness check that crashes on a fresh install is useless
            // for the one moment it is most wanted.
            return [];
        }

        if ($count === 0) {
            return [];
        }

        return [EnvironmentFinding::blocker(
            'notifications.mail_status',
            "{$count} notification(s) are recorded as sent by a transport that sends nothing.",
            'The record is false: those customers were told nothing. Anything reading that column '
            .'to decide whether somebody has been contacted -- support, reconciliation, a future '
            .'delivery follow-up -- is reading a lie. They need telling by hand.',
        )];
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function secureCookie(bool $exposed): array
    {
        if ($this->config->get('session.secure_cookie')) {
            return [EnvironmentFinding::ok('session.secure_cookie', 'On, so the session is not sent over plain HTTP.')];
        }

        $url = (string) $this->config->get('app.url');
        $https = str_starts_with($url, 'https://');

        if (! $exposed || ! $https) {
            return [EnvironmentFinding::warning(
                'session.secure_cookie',
                'Off.',
                'The session cookie will be sent over plain HTTP. Only a problem once the site is '
                .'actually served over https.',
            )];
        }

        return [EnvironmentFinding::blocker(
            'session.secure_cookie',
            'Off, on a site whose URL is https.',
            'The session cookie travels unencrypted on every request, so anyone on the same network '
            .'can read it and become that customer -- which on this platform means their wallet, '
            .'their orders and their bids.',
        )];
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function durableState(bool $exposed): array
    {
        $findings = [];

        foreach ([
            'session.driver' => 'sessions',
            'cache.default' => 'cache',
            'queue.default' => 'queued jobs',
        ] as $key => $what) {
            $driver = (string) $this->config->get($key);

            if (! in_array($driver, self::EPHEMERAL_DRIVERS, true)) {
                $findings[] = EnvironmentFinding::ok($key, "The {$driver} driver persists {$what}.");

                continue;
            }

            $findings[] = $exposed
                ? EnvironmentFinding::blocker(
                    $key,
                    "The {$driver} driver forgets {$what} at the end of every request.",
                    'A customer who logs in is logged out immediately, a cart does not survive a '
                    .'page load, and anything waiting in a queue is discarded unread. On a platform '
                    .'that holds credit balances this looks like the site losing people\'s money.',
                )
                : EnvironmentFinding::warning($key, "The {$driver} driver persists nothing.", 'Acceptable locally.');
        }

        return $findings;
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function payments(bool $exposed): array
    {
        $secret = trim((string) $this->config->get('paystack.secret_key'));
        $public = trim((string) $this->config->get('paystack.public_key'));

        if (! $exposed) {
            return [EnvironmentFinding::ok('paystack', 'Credentials are not required for local work.')];
        }

        $missing = [];

        if ($secret === '') {
            $missing[] = 'secret';
        }

        if ($public === '') {
            $missing[] = 'public';
        }

        if ($missing !== []) {
            return [EnvironmentFinding::blocker(
                'paystack',
                'No Paystack '.implode(' and ', $missing).' key configured.',
                'The site can take no money: checkout cannot initialise a transaction, and no '
                .'payment can be verified server-side. Nothing about this is visible until a '
                .'customer tries to pay.',
            )];
        }

        return [EnvironmentFinding::ok('paystack', 'Both keys are configured.')];
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function logging(bool $exposed): array
    {
        $level = $this->effectiveLogLevel();

        if ($level === null) {
            return [];
        }

        if ($level !== 'debug') {
            return [EnvironmentFinding::ok('logging.level', "Level is {$level}.")];
        }

        return $exposed
            ? [EnvironmentFinding::warning(
                'logging.level',
                'Level is debug in an environment reachable from the internet.',
                'Verbose logging writes request and query detail to disk indefinitely. Useful while '
                .'diagnosing, expensive to keep, and a liability to keep forever if any of it is '
                .'personal data.',
            )]
            : [EnvironmentFinding::warning('logging.level', 'Level is debug.', 'Acceptable locally.')];
    }

    /**
     * The level actually in force, following the `stack` channel to the first
     * channel that declares one.
     */
    private function effectiveLogLevel(): ?string
    {
        $default = (string) $this->config->get('logging.default');
        $channel = $this->config->get('logging.channels.'.$default);

        if (! is_array($channel)) {
            return null;
        }

        if (isset($channel['level'])) {
            return (string) $channel['level'];
        }

        foreach ((array) ($channel['channels'] ?? []) as $nested) {
            $level = $this->config->get('logging.channels.'.$nested.'.level');

            if (is_string($level)) {
                return $level;
            }
        }

        return null;
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function queuedMail(): array
    {
        if ($this->config->get('notifications.queue_mail') !== true) {
            return [EnvironmentFinding::ok('notifications.queue_mail', 'Off, so mail is sent inline and needs no worker.')];
        }

        return [EnvironmentFinding::warning(
            'notifications.queue_mail',
            'On, which hands notification email to a queue worker.',
            'This needs a persistent worker. The initial production target runs cron tasks rather '
            .'than long-lived processes, so with no worker running every email stays queued and '
            .'never leaves -- silently, and with the notification row claiming it was sent.',
        )];
    }

    /**
     * @return list<EnvironmentFinding>
     */
    private function supportContact(bool $exposed): array
    {
        try {
            $email = trim((string) settings()->get('support_email', ''));
        } catch (Throwable $e) {
            // A missing settings table means a fresh install that has not been
            // seeded. Reported by the seed step, not by this one.
            return [];
        }

        if ($email !== '') {
            return [EnvironmentFinding::ok('support_email', 'Set, so the published contact details are complete.')];
        }

        return [EnvironmentFinding::warning(
            'support_email',
            'No support email address is set.',
            'The published privacy notice and terms both have to name a way to contact the '
            .'operator. Without this they fall back to a contact form, which is a weaker answer to '
            .'a data-protection question than an address somebody can write to.',
        )];
    }

    /**
     * Whether the database answers, kept out of `inspect()` because it is a
     * different kind of question: a configuration value can be read before the
     * application has ever talked to its database, and a check that hard-fails
     * without one cannot report on a fresh install.
     */
    public function databaseReachable(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

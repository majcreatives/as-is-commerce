<?php

declare(strict_types=1);

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Referral;
use App\Models\User;
use App\Support\Environment\EnvironmentFinding;
use App\Support\Environment\EnvironmentInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Whether this installation is fit to be public.
 *
 * The stakes are in the conditions rather than the assertions. Every check here
 * exists because `.env.example` -- the file the release notes tell an operator
 * to build `.env` from -- ships `APP_DEBUG=true`, `MAIL_MAILER=log` and
 * `SESSION_SECURE_COOKIE=false`, because it is written for a developer. A site
 * built by copying it and filling in only the credentials is debug-on, unable to
 * send mail while recording every message as sent, and serving its session
 * cookie in the clear. None of that announces itself.
 *
 * The tests assert the two things that matter: that each condition produces the
 * right severity, and that the command can fail a deploy. Everything else is
 * presentation.
 */

beforeEach(function (): void {
    seedSettings();

    $this->inspector = app(EnvironmentInspector::class);
});

/**
 * Findings keyed by their dot-notation name, so a test can ask about one thing
 * without asserting the whole list.
 *
 * @return array<string, EnvironmentFinding>
 */
function findings(EnvironmentInspector $inspector): array
{
    $byKey = [];

    foreach ($inspector->inspect() as $finding) {
        $byKey[$finding->key] = $finding;
    }

    return $byKey;
}

function exposed(): void
{
    config(['app.env' => 'production']);
}

function localish(): void
{
    config(['app.env' => 'local']);
}

it('treats staging as exposed, because staging is on the public internet', function (): void {
    config(['app.env' => 'staging']);

    // The errors a customer can find on staging are the ones a customer can
    // find. Holding staging to a laxer standard than production is how staging
    // becomes the thing that gets promoted.
    expect(app(EnvironmentInspector::class)->isExposed())->toBeTrue();
});

it('calls developer defaults correct on a local machine', function (): void {
    localish();
    config(['app.debug' => true, 'mail.default' => 'log']);

    $found = findings($this->inspector);

    expect($found['app.debug']->severity)->toBe('warning')
        ->and($found['mail.default']->severity)->toBe('warning');
});

it('blocks a reachable site running in debug mode', function (): void {
    exposed();
    config(['app.debug' => true]);

    $finding = findings($this->inspector)['app.debug'];

    expect($finding->isBlocker())->toBeTrue()
        // A blocker nobody can act on is a blocker nobody fixes.
        ->and($finding->consequence)->toContain('stack trace');
});

it('passes a reachable site with debug off', function (): void {
    exposed();
    config(['app.debug' => false]);

    expect(findings($this->inspector)['app.debug']->severity)->toBe('ok');
});

it('blocks a mail transport that delivers nothing', function (string $mailer): void {
    exposed();
    config(['mail.default' => $mailer]);

    $finding = findings($this->inspector)['mail.default'];

    expect($finding->isBlocker())->toBeTrue()
        // The consequence, not the condition, is what makes somebody act.
        ->and($finding->consequence)->toContain('mail_status=sent');
})->with(['log', 'array']);

it('accepts a mail transport that delivers', function (): void {
    exposed();
    config(['mail.default' => 'smtp']);

    expect(findings($this->inspector)['mail.default']->severity)->toBe('ok');
});

it('catches notifications recorded as sent by a transport that sent nothing', function (): void {
    exposed();
    config(['mail.default' => 'log']);

    $user = bidder(0);
    $notification = new Notification;
    $notification->id = (string) Str::uuid();
    $notification->type = 'database';
    $notification->notifiable_type = User::class;
    $notification->notifiable_id = $user->id;
    $notification->data = '{}';
    $notification->event_type = NotificationType::OrderPaymentSuccess->value;
    $notification->mail_status = 'sent';
    $notification->mail_sent_at = now();
    $notification->save();

    $finding = findings($this->inspector)['notifications.mail_status'];

    // This is the whole reason the command exists. The row says the customer
    // was told; no message was ever sent; nothing anywhere says otherwise.
    expect($finding->isBlocker())->toBeTrue()
        ->and($finding->consequence)->toContain('reading a lie');
});

it('raises nothing about false records when the transport really delivers', function (): void {
    exposed();
    config(['mail.default' => 'smtp']);

    $user = bidder(0);
    $notification = new Notification;
    $notification->id = (string) Str::uuid();
    $notification->type = 'database';
    $notification->notifiable_type = User::class;
    $notification->notifiable_id = $user->id;
    $notification->data = '{}';
    $notification->event_type = NotificationType::OrderPaymentSuccess->value;
    $notification->mail_status = 'sent';
    $notification->mail_sent_at = now();
    $notification->save();

    // A genuine delivery recorded as sent is the column working. Reading the
    // same row as false here would make the check cry wolf on correct config.
    expect(findings($this->inspector))->not->toHaveKey('notifications.mail_status');
});

it('blocks a session cookie on plain http', function (): void {
    exposed();
    config(['app.url' => 'https://as-is-commerce.test', 'session.secure_cookie' => false]);

    $finding = findings($this->inspector)['session.secure_cookie'];

    expect($finding->isBlocker())->toBeTrue()
        ->and($finding->consequence)->toContain('become that customer');
});

it('only warns about the session cookie when the site is not on https', function (): void {
    exposed();
    config(['app.url' => 'http://as-is-commerce.test', 'session.secure_cookie' => false]);

    // The risk is conditional on the transport. Turning the cookie off for
    // good would simply log everybody out on a site served over http.
    expect(findings($this->inspector)['session.secure_cookie']->isWarning())->toBeTrue();
});

it('blocks a reachable site holding state in per-request memory', function (string $key): void {
    exposed();
    config([$key => 'array']);

    $finding = findings($this->inspector)[$key];

    expect($finding->isBlocker())->toBeTrue()
        ->and($finding->consequence)->toContain('losing people');
})->with(['session.driver', 'cache.default', 'queue.default']);

it('blocks a reachable site that cannot take a payment', function (): void {
    exposed();
    config(['paystack.secret_key' => '', 'paystack.public_key' => '']);

    $finding = findings($this->inspector)['paystack'];

    expect($finding->isBlocker())->toBeTrue()
        ->and($finding->consequence)->toContain('take no money');
});

it('passes a reachable site with both payment keys', function (): void {
    exposed();
    config(['paystack.secret_key' => 'sk_test_x', 'paystack.public_key' => 'pk_test_x']);

    expect(findings($this->inspector)['paystack']->severity)->toBe('ok');
});

it('blocks a reachable site with no application key', function (): void {
    exposed();
    config(['app.key' => '']);

    expect(findings($this->inspector)['app.key']->isBlocker())->toBeTrue();
});

it('warns about queued mail with no worker to run it', function (): void {
    exposed();
    config(['notifications.queue_mail' => true]);

    // Hostinger runs cron, not daemons. A worker that is not running turns
    // every email into a row that claims to have been sent.
    expect(findings($this->inspector)['notifications.queue_mail']->isWarning())->toBeTrue();
});

it('names the missing support contact a published policy needs', function (): void {
    exposed();
    settings()->set('support_email', '');

    $finding = findings($this->inspector)['support_email'];

    expect($finding->isWarning())->toBeTrue()
        ->and($finding->consequence)->toContain('privacy notice');
});

it('reports a support email that is set', function (): void {
    exposed();
    settings()->set('support_email', 'help@as-is-commerce.test');

    expect(findings($this->inspector)['support_email']->severity)->toBe('ok');
});

it('never prints the value of a secret', function (string $key): void {
    exposed();
    config([
        'paystack.secret_key' => 'sk_live_do_not_leak_me',
        'mail.default' => 'log',
        'app.debug' => true,
    ]);

    $printed = collect(app(EnvironmentInspector::class)->inspect())
        ->map(fn (EnvironmentFinding $f): string => $f->line())
        ->implode("\n");

    // The output is meant to be pasted into a ticket. A command that helps
    // somebody verify production must not be how a key reaches one.
    expect($printed)->not->toContain('do_not_leak_me');
})->with(['paystack', 'mail', 'debug']);

// ------------------------------------------------------------- The command

it('fails a strict run when a blocker is present', function (): void {
    exposed();
    config(['app.debug' => true, 'mail.default' => 'log']);

    $this->artisan('app:check-environment --strict')->assertExitCode(1);
});

it('passes a strict run on a correctly configured site', function (): void {
    exposed();
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://as-is-commerce.test',
        'mail.default' => 'smtp',
        'session.secure_cookie' => true,
        'session.driver' => 'database',
        'cache.default' => 'database',
        'queue.default' => 'database',
        'paystack.secret_key' => 'sk_test_x',
        'paystack.public_key' => 'pk_test_x',
        'notifications.queue_mail' => false,
    ]);
    settings()->set('support_email', 'help@as-is-commerce.test');

    $this->artisan('app:check-environment --strict')
        ->expectsOutputToContain('Nothing blocking')
        ->assertExitCode(0);
});

it('reports rather than fails without strict, so a laptop is not gated', function (): void {
    exposed();
    config(['app.debug' => true]);

    $this->artisan('app:check-environment')
        ->expectsOutputToContain('blocker')
        ->assertExitCode(0);
});

it('changes nothing when it runs', function (): void {
    exposed();
    config(['app.debug' => true, 'mail.default' => 'log']);

    $user = bidder(0);
    $before = [
        'settings' => DB::table('settings')->count(),
        'referrals' => Referral::count(),
        'notifications' => Notification::count(),
    ];

    $this->artisan('app:check-environment --strict')->assertExitCode(1);

    expect(DB::table('settings')->count())->toBe($before['settings'])
        ->and(Referral::count())->toBe($before['referrals'])
        ->and(Notification::count())->toBe($before['notifications'])
        ->and($user->fresh())->not->toBeNull();
});

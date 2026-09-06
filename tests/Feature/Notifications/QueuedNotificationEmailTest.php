<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationEmail;
use App\Mail\PlatformNotificationMail;
use App\Models\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Finder\SplFileInfo;

/*
 * Queued notification email.
 *
 * The only queued work on this platform. It exists because `auctions:tick`
 * closes auctions and emails their winners inline, on a schedule of every
 * minute with a five-minute overlap lock -- so SMTP latency was inside the
 * sweep.
 *
 * It is OFF by default, and these tests hold that: the initial production
 * target runs cron rather than persistent processes, and a deployment without
 * a worker must behave exactly as it did before rather than going quiet.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();
});

// -------------------------------------------------------- Off by default

it('sends email inline unless queueing is switched on', function (): void {
    expect(config('notifications.queue_mail'))->toBeFalse();

    Bus::fake();
    Mail::fake();

    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 200);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    // Nothing queued: a deployment with no worker still sends.
    Bus::assertNotDispatched(SendNotificationEmail::class);
});

it('records the mail outcome on the row when sending inline', function (): void {
    Mail::fake();

    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 200);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    $won = notificationsFor($winner->fresh(), NotificationType::AuctionWon)->first();

    expect($won)->not->toBeNull()
        ->and($won->mail_status)->toBe('sent');
});

// ----------------------------------------------------------- Switched on

it('queues the email instead when the flag is set', function (): void {
    config(['notifications.queue_mail' => true]);

    Bus::fake();

    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 200);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    Bus::assertDispatched(SendNotificationEmail::class);

    $won = notificationsFor($winner->fresh(), NotificationType::AuctionWon)->first();

    // Honest third state. The message has not left, and claiming `sent` or
    // `failed` would both be wrong.
    expect($won->mail_status)->toBe('queued');
});

it('sends the email and records the outcome when the worker runs', function (): void {
    config(['notifications.queue_mail' => true]);

    Mail::fake();

    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 200);

    // The queue connection is `sync` under test, so the job runs immediately
    // and we see exactly what a worker would do with it.
    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    $won = notificationsFor($winner->fresh(), NotificationType::AuctionWon)->first();

    expect($won->mail_status)->toBe('sent')
        ->and($won->mail_sent_at)->not->toBeNull();
});

it('does not send twice when the job is redelivered', function (): void {
    Mail::fake();

    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 200);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    $won = notificationsFor($winner->fresh(), NotificationType::AuctionWon)->first();
    $before = Mail::sent(PlatformNotificationMail::class)->count();

    // Already marked sent. A retry must recognise that rather than mailing
    // the customer a second time.
    (new SendNotificationEmail($won->id, (string) $winner->email))->handle();

    expect(Mail::sent(PlatformNotificationMail::class)->count())->toBe($before);
});

it('does nothing when the notification is gone by the time the worker runs', function (): void {
    Mail::fake();

    $job = new SendNotificationEmail('a-notification-that-no-longer-exists', 'someone@example.test');

    // Not an error. There is nothing left to describe.
    $job->handle();

    Mail::assertNothingSent();
});

// ------------------------------------ The business event never depends on it

it('closes the auction even when queueing the email fails outright', function (): void {
    config(['notifications.queue_mail' => true]);

    // A queue that refuses everything, which is what an unreachable driver
    // looks like from here.
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue is down'));

    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 200);

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    // The auction closed, the winner won, the settlement order exists. The
    // message is the only thing in doubt.
    expect($closed->winner_user_id)->toBe($winner->id)
        ->and($closed->settlementOrder()->count())->toBe(1);
});

it('leaves the in-app notification readable however the email went', function (): void {
    config(['notifications.queue_mail' => true]);

    Bus::fake();

    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 200);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    // The in-app notification is written and committed before the email is
    // considered at all, so the customer sees the event either way.
    expect(Notification::query()->where('notifiable_id', $winner->id)->exists())->toBeTrue();
});

it('queues no financial work of any kind', function (): void {
    config(['notifications.queue_mail' => true]);

    Bus::fake();

    $auction = liveAuction();
    $winner = bidder(1_000);

    placeBid($auction->fresh(), $winner, 200);
    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    // Communication only. Bids, credits, inventory, orders and settlement all
    // completed synchronously inside their transactions.
    Bus::assertDispatchedTimes(SendNotificationEmail::class, 2);

});

it('has exactly one job class, and it carries a message', function (): void {
    // The structural guarantee behind the test above. Nothing financial can be
    // queued because there is nothing to queue it with: this is the only job
    // in the application, and it sends an email about something that has
    // already committed.
    $jobs = collect(File::allFiles(app_path('Jobs')))
        ->map(fn (SplFileInfo $f): string => $f->getFilenameWithoutExtension())
        ->sort()
        ->values()
        ->all();

    expect($jobs)->toBe(['SendNotificationEmail']);
});

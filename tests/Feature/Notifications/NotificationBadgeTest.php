<?php

declare(strict_types=1);

use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * What earns the badge on the menu.
 *
 * A badge is an attention signal, and it stops being one when it counts
 * everything ever sent: unread rows only leave the count when somebody marks
 * them read, so a customer who rarely opens the notification centre would carry
 * a number that means nothing. The badge counts unread AND recent.
 *
 * The notification centre keeps the complete count. These tests hold both
 * halves: the badge is bounded, and nothing is hidden from the record.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

/**
 * Put a notification in front of somebody, optionally as if it had arrived
 * some days ago.
 */
function badgeNotification(User $user, int $daysOld = 0, bool $read = false): Notification
{
    $notification = app(NotificationDispatcher::class)->send(
        recipient: $user,
        type: NotificationType::BidPlaced,
        title: 'Something happened',
        message: 'The body of the message.',
        eventKey: 'badge-'.Str::uuid()->toString(),
    );

    // Straight to the table: a notification's age is a fact about when it was
    // written, and the only way to test the window is to have written it then.
    DB::table('notifications')->where('id', $notification->id)->update([
        'created_at' => now()->subDays($daysOld),
        'read_at' => $read ? now() : null,
    ]);

    return $notification;
}

// ------------------------------------------------------------ The count

it('counts only unread notifications that are recent', function (): void {
    $user = userWithRole('customer');

    badgeNotification($user, daysOld: 0);
    badgeNotification($user, daysOld: 10);
    badgeNotification($user, daysOld: 40);              // unread but stale
    badgeNotification($user, daysOld: 0, read: true);   // recent but read

    expect($user->recentUnreadNotificationCount())->toBe(2);
});

it('leaves the complete unread count to the notification centre', function (): void {
    // The badge is bounded; the record is not. The stale notification is still
    // unread and the centre still says so.
    $user = userWithRole('customer');

    badgeNotification($user, daysOld: 0);
    badgeNotification($user, daysOld: 40);

    expect($user->recentUnreadNotificationCount())->toBe(1)
        ->and($user->unreadNotificationCount())->toBe(2);
});

it('ships with a thirty day window', function (): void {
    expect(settings()->getInt('notification_badge_window_days'))->toBe(30);
});

it('takes its window from a setting rather than a constant', function (): void {
    $user = userWithRole('customer');

    badgeNotification($user, daysOld: 3);
    badgeNotification($user, daysOld: 10);

    settings()->set('notification_badge_window_days', 7);

    expect($user->recentUnreadNotificationCount())->toBe(1);

    settings()->set('notification_badge_window_days', 14);

    expect($user->recentUnreadNotificationCount())->toBe(2);
});

it('counts every unread notification when the window is zero', function (): void {
    // Zero is stated, not an accident: it means no window, and is a choice an
    // administrator makes rather than a default nobody chose.
    $user = userWithRole('customer');

    badgeNotification($user, daysOld: 0);
    badgeNotification($user, daysOld: 400);

    settings()->set('notification_badge_window_days', 0);

    expect($user->recentUnreadNotificationCount())->toBe(2);
});

it('counts only the customer own notifications', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    badgeNotification($mine);
    badgeNotification($theirs);
    badgeNotification($theirs);

    expect($mine->recentUnreadNotificationCount())->toBe(1)
        ->and($theirs->recentUnreadNotificationCount())->toBe(2);
});

it('stops counting a notification once it is read', function (): void {
    $user = userWithRole('customer');
    $notification = badgeNotification($user);

    expect($user->recentUnreadNotificationCount())->toBe(1);

    $notification->markAsRead();

    expect($user->fresh()->recentUnreadNotificationCount())->toBe(0);
});

// --------------------------------------------------- On the mobile menu

it('shows the count on the menu button without opening the menu', function (): void {
    $user = userWithRole('customer');

    badgeNotification($user);
    badgeNotification($user);
    badgeNotification($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-menu-notification-badge', false);
});

it('tells assistive technology what the badge says', function (): void {
    // The badge itself is hidden from screen readers, or it would be announced
    // twice; the button's accessible name carries the count instead.
    $user = userWithRole('customer');

    badgeNotification($user);
    badgeNotification($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Toggle navigation, 2 unread notifications');
});

it('uses the singular for one notification', function (): void {
    $user = userWithRole('customer');
    badgeNotification($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('Toggle navigation, 1 unread notification', false)
        ->assertDontSee('1 unread notifications', false);
});

it('shows no badge and no count when nothing is waiting', function (): void {
    $user = userWithRole('customer');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-menu-notification-badge', false)
        ->assertDontSee('unread notification');
});

it('does not badge a notification that has aged out of the window', function (): void {
    $user = userWithRole('customer');

    badgeNotification($user, daysOld: 40);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-menu-notification-badge', false);
});

it('caps a large count rather than printing it', function (): void {
    $user = userWithRole('customer');

    foreach (range(1, 101) as $i) {
        badgeNotification($user);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('99+')
        ->assertSee('more than 99 unread notifications')
        ->assertDontSee('101 unread');
});

it('shows a signed-out visitor no notification badge at all', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-menu-notification-badge', false)
        ->assertDontSee('unread notification');
});

it('keeps the count inside the menu as well', function (): void {
    // The drawer's own Notifications link still carries it, marked for screen
    // readers, and it is the same figure as the button's.
    $user = userWithRole('customer');

    badgeNotification($user);
    badgeNotification($user);

    $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

    expect(substr_count($html, '<span class="sr-only"> unread</span>'))->toBe(2)
        ->and($html)->toContain('Toggle navigation, 2 unread notifications');
});

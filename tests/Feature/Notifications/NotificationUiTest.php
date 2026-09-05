<?php

declare(strict_types=1);

use App\Domain\Notifications\Services\NotificationDispatcher;
use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Livewire\Admin\Notifications\NotificationIndex;
use App\Livewire\Notifications\NotificationCentre;
use App\Livewire\Profile\NotificationPreferencesForm;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The notification centre, the preferences screen, and the boundaries around
 * both.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
    $this->dispatcher = app(NotificationDispatcher::class);
});

/**
 * Put a notification in front of somebody.
 */
function notify(User $user, string $title = 'Something happened', ?string $key = null): ?Notification
{
    return app(NotificationDispatcher::class)->send(
        recipient: $user,
        type: NotificationType::BidPlaced,
        title: $title,
        message: 'The body of the message.',
        eventKey: $key ?? 'key-'.Str::uuid()->toString(),
        actionUrl: '/auctions',
        actionLabel: 'Open',
    );
}

// ---------------------------------------------------------- The centre

it('lists a customer own notifications', function (): void {
    $user = userWithRole('customer');
    notify($user, 'Your bid was accepted');

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->assertOk()
        ->assertSee('Your bid was accepted')
        ->assertSee('The body of the message.');
});

it('shows an empty state rather than an empty table', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(NotificationCentre::class)
        ->assertSee('No notifications yet');
});

it('filters to unread', function (): void {
    $user = userWithRole('customer');
    $read = notify($user, 'Already seen');
    notify($user, 'Not yet seen');

    $read->markAsRead();

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->set('filter', 'unread')
        ->assertSee('Not yet seen')
        ->assertDontSee('Already seen');
});

it('marks one notification read', function (): void {
    $user = userWithRole('customer');
    $notification = notify($user);

    expect($notification->isUnread())->toBeTrue();

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->call('markRead', $notification->id);

    expect($notification->fresh()->isUnread())->toBeFalse();
});

it('marks everything read at once', function (): void {
    $user = userWithRole('customer');

    foreach (range(1, 4) as $i) {
        notify($user, "Message {$i}");
    }

    expect($user->unreadNotificationCount())->toBe(4);

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->call('markAllRead');

    expect($user->fresh()->unreadNotificationCount())->toBe(0);
});

it('paginates a long list', function (): void {
    $user = userWithRole('customer');

    foreach (range(1, 25) as $i) {
        notify($user, "Message {$i}");
    }

    Livewire::actingAs($user)
        ->test(NotificationCentre::class)
        ->assertOk()
        // Twenty a page, so the twenty-fifth is not on the first.
        ->assertViewHas('notifications', fn ($page): bool => $page->count() === 20);
});

it('shows the unread count in the navigation', function (): void {
    $user = userWithRole('customer');
    notify($user);
    notify($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Notifications');

    expect($user->fresh()->unreadNotificationCount())->toBe(2);
});

// ------------------------------------------------------------ Security

it('does not list another customer notifications', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    notify($mine, 'Mine to read');
    notify($theirs, 'Theirs to read');

    Livewire::actingAs($mine)
        ->test(NotificationCentre::class)
        ->assertSee('Mine to read')
        ->assertDontSee('Theirs to read');
});

/*
 * The id is resolved through the signed-in user's own relation, so somebody
 * else's simply finds nothing. There is no branch that could act on it.
 */
it('cannot mark another customer notification read', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    $target = notify($theirs);

    Livewire::actingAs($mine)
        ->test(NotificationCentre::class)
        ->call('markRead', $target->id);

    expect($target->fresh()->isUnread())->toBeTrue();
});

it('cannot mark another customer notifications read in bulk', function (): void {
    $mine = userWithRole('customer');
    $theirs = userWithRole('customer');

    notify($theirs);
    notify($mine);

    Livewire::actingAs($mine)
        ->test(NotificationCentre::class)
        ->call('markAllRead');

    expect($theirs->fresh()->unreadNotificationCount())->toBe(1)
        ->and($mine->fresh()->unreadNotificationCount())->toBe(0);
});

it('requires a signed-in account', function (): void {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

// -------------------------------------------------------- Preferences

it('shows only the categories a customer may switch off', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(NotificationPreferencesForm::class)
        ->assertOk()
        ->assertSee('Bidding activity')
        ->assertSee('Auction results')
        // Transactional has no switch, and the screen says why.
        ->assertSee('always sent')
        ->assertDontSee('Payments and orders</p>', escape: false);
});

it('saves a switched-off category', function (): void {
    $user = userWithRole('customer');

    Livewire::actingAs($user)
        ->test(NotificationPreferencesForm::class)
        ->set('preferences.bidding.in_app', false)
        ->set('preferences.bidding.email', false)
        ->call('save');

    $preferences = $user->fresh()->notificationPreferences();

    expect($preferences->inAppEnabled(NotificationCategory::Bidding))->toBeFalse()
        ->and($preferences->emailEnabled(NotificationCategory::Bidding))->toBeFalse()
        // The other category is untouched.
        ->and($preferences->inAppEnabled(NotificationCategory::AuctionResults))->toBeTrue();
});

/*
 * The value object refuses it, so a fabricated category in the request reaches
 * nothing and cannot disable a transactional notification.
 */
it('cannot switch off a transactional category', function (): void {
    $user = userWithRole('customer');

    Livewire::actingAs($user)
        ->test(NotificationPreferencesForm::class)
        ->set('preferences.transactional.in_app', false)
        ->call('save');

    $preferences = $user->fresh()->notificationPreferences();

    expect($preferences->allowsInApp(NotificationType::OrderPaymentSuccess))->toBeTrue()
        ->and($preferences->allowsInApp(NotificationType::OrderFulfilmentBlocked))->toBeTrue()
        ->and($preferences->allowsInApp(NotificationType::AuctionWon))->toBeTrue();
});

// ------------------------------------------------------------- Admin

it('shows staff a failed delivery', function (): void {
    $user = userWithRole('customer');
    $notification = notify($user);

    $notification->mail_status = 'failed';
    $notification->mail_failure_reason = 'Mailbox does not exist';
    $notification->save();

    Livewire::actingAs($this->admin)
        ->test(NotificationIndex::class)
        ->assertOk()
        ->assertSee('could not be delivered')
        ->assertSee('Mailbox does not exist')
        // And says plainly that the business event was unaffected.
        ->assertSee('completed normally');
});

it('does not surface a customer contact details to staff', function (): void {
    $user = userWithRole('customer');
    $user->update(['email' => 'private@example.test', 'name' => 'Akosua Darko']);

    $notification = notify($user);
    $notification->mail_status = 'failed';
    $notification->mail_failure_reason = 'Rejected';
    $notification->save();

    Livewire::actingAs($this->admin)
        ->test(NotificationIndex::class)
        ->assertSee('Akosua Darko')
        // The name identifies the row. The address and phone do not belong
        // on a staff listing.
        ->assertDontSee('private@example.test')
        ->assertDontSee($user->phone);
});

it('offers staff no way to edit or resend a notification', function (): void {
    $user = userWithRole('customer');
    $notification = notify($user);
    $notification->mail_status = 'failed';
    $notification->save();

    $component = Livewire::actingAs($this->admin)->test(NotificationIndex::class);

    $component->assertDontSee('Resend')
        ->assertDontSee('Delete')
        ->assertDontSee('Edit');

    // And there is no method to call, which is the part that matters.
    expect(method_exists(NotificationIndex::class, 'resend'))->toBeFalse()
        ->and(method_exists(NotificationIndex::class, 'delete'))->toBeFalse()
        ->and(method_exists(NotificationIndex::class, 'markRead'))->toBeFalse();
});

it('forbids a customer from the admin notification screen', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(NotificationIndex::class)
        ->assertForbidden();
});

it('keeps a customer out of the admin notification route', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('admin.notifications'))
        ->assertForbidden();
});

it('does not gate the admin screen on anything a customer holds', function (): void {
    $customer = userWithRole('customer');

    expect($customer->can('notifications.inspect'))->toBeFalse();
});

it('lets an administrator reach the notification screen', function (): void {
    $this->actingAs($this->admin)->get(route('admin.notifications'))->assertOk();
});

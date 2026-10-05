<?php

declare(strict_types=1);

use App\Enums\NewsletterStatus;
use App\Jobs\SendNewsletterConfirmation;
use App\Livewire\Admin\Newsletter\SubscriberIndex;
use App\Livewire\Newsletter\ConfirmSubscription;
use App\Livewire\Newsletter\Signup;
use App\Livewire\Newsletter\Unsubscribe;
use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscriber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The newsletter list, the double opt-in, and the two email links.
 *
 * The theme of most of these is that a submitted form subscribes NOBODY, and
 * that the email links are the only thing that does.
 */
describe('newsletter signup', function (): void {

    it('records a request as pending rather than subscribing', function (): void {
        Queue::fake();

        Livewire::test(Signup::class)
            ->set('email', 'Kwame@Example.com ')
            ->call('requestConfirmation')
            ->assertHasNoErrors();

        $subscriber = NewsletterSubscriber::query()->firstOrFail();

        expect($subscriber->status)->toBe(NewsletterStatus::Pending)
            ->and($subscriber->email)->toBe('kwame@example.com')
            ->and($subscriber->consented_at)->toBeNull()
            ->and($subscriber->confirmation_token)->not->toBeNull();
    });

    it('queues a confirmation email and does not send it inline', function (): void {
        Queue::fake();
        Mail::fake();

        Livewire::test(Signup::class)
            ->set('email', 'kwame@example.com')
            ->call('requestConfirmation');

        Queue::assertPushed(SendNewsletterConfirmation::class, function (SendNewsletterConfirmation $job): bool {
            // The job holds the id, so this proves the dispatch happened and the
            // queue is the transport rather than the request.
            return $job->subscriberId === (string) NewsletterSubscriber::query()->firstOrFail()->id;
        });

        Mail::assertNothingSent();
    });

    it('says the same thing whether or not the address was already subscribed', function (): void {
        Queue::fake();

        $existing = NewsletterSubscriber::factory()->subscribed()->create([
            'email' => 'kwame@example.com',
        ]);

        Livewire::test(Signup::class)
            ->set('email', 'kwame@example.com')
            ->call('requestConfirmation')
            ->assertSet('confirmationRequested', true);

        expect($existing->fresh()->status)->toBe(NewsletterStatus::Subscribed);

        // And a brand new address produces the identical visible state, so the
        // form cannot be used to find out who is already on the list.
        Livewire::test(Signup::class)
            ->set('email', 'someone-else@example.com')
            ->call('requestConfirmation')
            ->assertSet('confirmationRequested', true);
    });

    it('does not queue a second email for an address that is already subscribed', function (): void {
        Queue::fake();

        NewsletterSubscriber::factory()->subscribed()->create(['email' => 'kwame@example.com']);

        Livewire::test(Signup::class)
            ->set('email', 'kwame@example.com')
            ->call('requestConfirmation');

        Queue::assertNothingPushed();
    });

    it('requires an address that looks like an address', function (): void {
        Queue::fake();

        Livewire::test(Signup::class)
            ->set('email', 'not-an-address')
            ->call('requestConfirmation')
            ->assertHasErrors(['email']);

        Queue::assertNothingPushed();
    });

    it('empties the field after submitting', function (): void {
        Queue::fake();

        Livewire::test(Signup::class)
            ->set('email', 'kwame@example.com')
            ->call('requestConfirmation')
            ->assertSet('email', '');
    });

    it('throttles repeated attempts at the same address', function (): void {
        Queue::fake();

        $component = Livewire::test(Signup::class);

        foreach (range(1, 3) as $ignored) {
            $component->set('email', 'kwame@example.com')->call('requestConfirmation');
        }

        $component->set('email', 'kwame@example.com')
            ->call('requestConfirmation')
            ->assertHasErrors(['email']);

        expect(NewsletterSubscriber::query()->count())->toBe(1);
    });

    it('counts differently spelled attempts at one address against the same limit', function (): void {
        Queue::fake();

        $component = Livewire::test(Signup::class);

        // The same person casing their address differently must not buy
        // themselves a fresh allowance of three sends.
        foreach (['kwame@example.com', 'Kwame@Example.com', ' KWAME@EXAMPLE.COM '] as $email) {
            $component->set('email', $email)->call('requestConfirmation');
        }

        $component->set('email', 'kwame@example.com')
            ->call('requestConfirmation')
            ->assertHasErrors(['email']);
    });
});

describe('newsletter confirmation link', function (): void {

    it('subscribes the address and records when it was proved', function (): void {
        $subscriber = NewsletterSubscriber::factory()->create();
        $token = $subscriber->confirmation_token;

        Livewire::test(ConfirmSubscription::class, ['token' => $token])
            ->assertSet('outcome', 'confirmed');

        $subscriber->refresh();

        expect($subscriber->status)->toBe(NewsletterStatus::Subscribed)
            ->and($subscriber->consented_at)->not->toBeNull()
            ->and($subscriber->confirmation_token)->toBeNull();
    });

    it('spends the token, so the same email cannot be replayed', function (): void {
        $subscriber = NewsletterSubscriber::factory()->create();
        $token = $subscriber->confirmation_token;

        Livewire::test(ConfirmSubscription::class, ['token' => $token]);

        // Somebody who later unsubscribes must not be able to be re-subscribed
        // by an old confirmation email still sitting in their inbox.
        $subscriber->refresh()->unsubscribe();

        Livewire::test(ConfirmSubscription::class, ['token' => $token])
            ->assertSet('outcome', 'already');

        expect($subscriber->fresh()->status)->toBe(NewsletterStatus::Unsubscribed);
    });

    it('reports a spent or unknown token as already-handled rather than failing', function (): void {
        Livewire::test(ConfirmSubscription::class, ['token' => 'not-a-real-token'])
            ->assertSet('outcome', 'already')
            ->assertStatus(200);
    });

    it('is reachable without signing in', function (): void {
        $subscriber = NewsletterSubscriber::factory()->create();

        $this->get($subscriber->confirmationUrl())
            ->assertOk();

        expect($subscriber->fresh()->status)->toBe(NewsletterStatus::Subscribed);
    });
});

describe('newsletter unsubscribe link', function (): void {

    it('removes a subscriber immediately, with no confirmation step', function (): void {
        $subscriber = NewsletterSubscriber::factory()->subscribed()->create();

        Livewire::test(Unsubscribe::class, ['token' => $subscriber->unsubscribe_token])
            ->assertSet('outcome', 'unsubscribed');

        $subscriber->refresh();

        expect($subscriber->status)->toBe(NewsletterStatus::Unsubscribed)
            ->and($subscriber->unsubscribed_at)->not->toBeNull()
            ->and($subscriber->consented_at)->not->toBeNull();
    });

    it('works from pending, so an unconfirmed request can still be stopped', function (): void {
        $subscriber = NewsletterSubscriber::factory()->create();

        Livewire::test(Unsubscribe::class, ['token' => $subscriber->unsubscribe_token])
            ->assertSet('outcome', 'unsubscribed');

        $subscriber->refresh();

        expect($subscriber->status)->toBe(NewsletterStatus::Unsubscribed)
            ->and($subscriber->confirmation_token)->toBeNull();
    });

    it('is idempotent, because people search old emails', function (): void {
        $subscriber = NewsletterSubscriber::factory()->subscribed()->create();

        Livewire::test(Unsubscribe::class, ['token' => $subscriber->unsubscribe_token])
            ->assertSet('outcome', 'unsubscribed');

        Livewire::test(Unsubscribe::class, ['token' => $subscriber->unsubscribe_token])
            ->assertSet('outcome', 'already');

        expect($subscriber->fresh()->status)->toBe(NewsletterStatus::Unsubscribed);
    });

    it('never implies somebody is still subscribed when the token is unknown', function (): void {
        Livewire::test(Unsubscribe::class, ['token' => 'not-a-real-token'])
            ->assertSet('outcome', 'unknown')
            ->assertStatus(200);
    });

    it('keeps its token valid, so a link from an old email still works', function (): void {
        $subscriber = NewsletterSubscriber::factory()->subscribed()->create();
        $token = $subscriber->unsubscribe_token;

        Livewire::test(Unsubscribe::class, ['token' => $token]);

        // They come back later and sign up again. The confirmation token is
        // replaced; the unsubscribe token must not be, or the email already in
        // their inbox stops working.
        NewsletterSubscriber::request($subscriber->fresh()->email);

        expect($subscriber->fresh()->unsubscribe_token)->toBe($token);
    });
});

describe('newsletter resubscribe', function (): void {

    it('puts an unsubscribed address back through confirmation, not straight back on the list', function (): void {
        $subscriber = NewsletterSubscriber::factory()->unsubscribed()->create();

        $subscriber = NewsletterSubscriber::request($subscriber->email);

        expect($subscriber->status)->toBe(NewsletterStatus::Pending)
            ->and($subscriber->consented_at)->toBeNull()
            ->and($subscriber->confirmation_token)->not->toBeNull();
    });

    it('does not let a third party silently re-add somebody', function (): void {
        $subscriber = NewsletterSubscriber::factory()->unsubscribed()->create();

        $request = NewsletterSubscriber::request($subscriber->email);

        // Pending, not subscribed. Somebody else's stray form submission cannot
        // undo a request to leave without the owner clicking a link.
        expect($request->status)->toBe(NewsletterStatus::Pending);
    });
});

describe('newsletter confirmation email job', function (): void {

    it('sends the confirmation link', function (): void {
        Mail::fake();

        $subscriber = NewsletterSubscriber::factory()->create();

        SendNewsletterConfirmation::dispatchSync($subscriber->id);

        Mail::assertSent(
            NewsletterConfirmationMail::class,
            fn (NewsletterConfirmationMail $mail): bool => $mail->hasTo($subscriber->email)
                && str_contains((string) $mail->confirmUrl, (string) $subscriber->confirmation_token),
        );
    });

    it('does not send anything for an address that has already confirmed', function (): void {
        Mail::fake();

        $subscriber = NewsletterSubscriber::factory()->subscribed()->create();

        SendNewsletterConfirmation::dispatchSync($subscriber->id);

        Mail::assertNothingSent();
    });

    it('stops on a retry when the address has moved on in the meantime', function (): void {
        Mail::fake();

        $subscriber = NewsletterSubscriber::factory()->create();

        // The job queued, the transport failed, and by the time it retried the
        // person had already clicked a link from an earlier delivery.
        $subscriber->confirm();

        SendNewsletterConfirmation::dispatchSync($subscriber->id);

        Mail::assertNothingSent();
    });
});

describe('newsletter admin list', function (): void {

    it('is refused without the permission', function (): void {
        $admin = userWithRole('admin');
        Role::findByName('admin')->revokePermissionTo('newsletter.view');

        $this->actingAs($admin->fresh())
            ->get(route('admin.newsletter.index'))
            ->assertForbidden();
    });

    it('is reachable with the permission', function (): void {
        $admin = userWithRole('admin');

        $this->actingAs($admin->fresh())
            ->get(route('admin.newsletter.index'))
            ->assertOk();
    });

    it('shows counts by status', function (): void {
        $admin = userWithRole('admin');

        NewsletterSubscriber::factory()->count(2)->subscribed()->create();
        NewsletterSubscriber::factory()->create();
        NewsletterSubscriber::factory()->unsubscribed()->create();

        Livewire::actingAs($admin)
            ->test(SubscriberIndex::class)
            ->assertSee('Subscribed')
            ->assertSee('Awaiting confirmation');
    });

    it('defaults to the subscribed addresses and can be widened to all', function (): void {
        $admin = userWithRole('admin');

        NewsletterSubscriber::factory()->subscribed()->create(['email' => 'on-list@example.com']);
        NewsletterSubscriber::factory()->create(['email' => 'waiting@example.com']);

        Livewire::actingAs($admin)
            ->test(SubscriberIndex::class)
            ->assertSee('on-list@example.com')
            ->assertDontSee('waiting@example.com');

        Livewire::actingAs($admin)
            ->test(SubscriberIndex::class)
            ->set('filter', 'all')
            ->assertSee('on-list@example.com')
            ->assertSee('waiting@example.com');
    });

    it('treats a wildcard in the search box as literal text', function (): void {
        $admin = userWithRole('admin');

        NewsletterSubscriber::factory()->subscribed()->create(['email' => 'real@example.com']);

        Livewire::actingAs($admin)
            ->test(SubscriberIndex::class)
            ->set('search', '%')
            ->assertDontSee('real@example.com');
    });

    it('falls back to the default filter for a crafted status', function (): void {
        $admin = userWithRole('admin');

        NewsletterSubscriber::factory()->subscribed()->create(['email' => 'on-list@example.com']);

        // An unknown status must not read as "there are no subscribers", which is
        // what a filter nobody recognises would otherwise produce.
        Livewire::actingAs($admin)
            ->test(SubscriberIndex::class)
            ->set('filter', 'not-a-status')
            ->assertSee('on-list@example.com');
    });

    it('is read-only: exposes no mutating action at all', function (): void {
        $admin = userWithRole('admin');

        $subscriber = NewsletterSubscriber::factory()->subscribed()->create();

        $component = Livewire::actingAs($admin)->test(SubscriberIndex::class);

        foreach (['delete', 'unsubscribe', 'resend', 'remove', 'export'] as $action) {
            expect(method_exists($component->instance(), $action))->toBeFalse();
        }

        expect($subscriber->fresh()->status)->toBe(NewsletterStatus::Subscribed);
    });
});

describe('newsletter database constraints', function (): void {
    it('refuses two rows for one address', function (): void {
        NewsletterSubscriber::factory()->create(['email' => 'kwame@example.com']);

        // Straight to the driver rather than through request(), which handles a
        // repeat gracefully by design. The unique index is the thing being
        // tested here, and only a bypass reaches it.
        expect(fn (): NewsletterSubscriber => NewsletterSubscriber::factory()->create([
            'email' => 'kwame@example.com',
        ]))->toThrow(QueryException::class);
    });

    it('lets any number of confirmed rows carry no confirmation token', function (): void {
        // MySQL and MariaDB both allow repeated NULLs under a unique index. That
        // is load-bearing: a confirmed subscriber has no confirmation token, so
        // without it the second person to confirm would collide with the first
        // and double opt-in would be impossible to complete twice.
        NewsletterSubscriber::factory()->count(3)->subscribed()->create();

        expect(NewsletterSubscriber::query()
            ->whereNull('confirmation_token')
            ->count())->toBe(3);
    });

    it('refuses two rows sharing an unsubscribe token', function (): void {
        $token = Str::random(64);

        NewsletterSubscriber::factory()->create(['unsubscribe_token' => $token]);

        expect(fn (): NewsletterSubscriber => NewsletterSubscriber::factory()->create([
            'unsubscribe_token' => $token,
        ]))->toThrow(QueryException::class);
    });
});

describe('newsletter placement', function (): void {
    it('offers the form in a modal on the front page', function (): void {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Hear about new auctions')
            // The input id, because that is ours and stays put. Livewire's own
            // component marker contains a generated wire:id and would be an
            // assertion about Livewire rather than about this feature.
            ->assertSee('id="newsletter-email"', false);
    });

    it('offers the form as a dialog rather than as loose markup', function (): void {
        $html = $this->get(route('home'))->assertOk()->getContent();

        // A panel that appears on its own has to be announced as one. Without
        // role="dialog" and aria-modal, a screen reader has no reason to treat
        // this as anything more than a div that used to be empty, and the
        // reader behind it is not told that it is no longer reachable.
        expect($html)
            ->toContain('role="dialog"')
            ->toContain('aria-modal="true"')
            ->toContain('aria-labelledby="newsletter-prompt-title"');

        // The three ways out. Escape is a keyboard action and has no visible
        // control to assert on, so the close button is checked here instead.
        expect($html)
            ->toContain('keydown.escape.window="dismiss()"')
            ->toContain('click.outside="dismiss()"')
            ->toContain('x-on:click="dismiss()"');
    });

    it('waits before opening rather than covering the shop on arrival', function (): void {
        // The delay is a product decision, not a tuning knob, so it is pinned:
        // somebody arriving at an eight-second decision should not find it has
        // become a two-second one without anyone choosing that.
        $html = $this->get(route('home'))->assertOk()->getContent();
        expect($html)->toContain('newsletterPrompt()');

        $source = file_get_contents(resource_path('js/newsletter-prompt.js'));
        expect($source)->toContain('DELAY_MS = 8000');
    });

    it('keeps the form out of the pages it has no business interrupting', function (): void {
        foreach (['products.index', 'auctions.index', 'login'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertDontSee('id="newsletter-email"', false);
        }
    });

    it('takes the form out of the footer entirely', function (): void {
        // The prompt is a front-page modal and not a footer element, so the
        // footer no longer carries a second copy of the same form. A visitor
        // would otherwise be able to sign up twice from one page, once by
        // scrolling and once by being interrupted.
        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->toContain('<footer');
        expect(substr_count($html, 'id="newsletter-email"'))->toBe(1);
    });

    it('stops asking a reader who has already closed it', function (): void {
        // Thirty days, not forever. The cookie answers "have we already asked",
        // and nothing about subscribing depends on it -- but a dismissal that
        // silently became permanent would be a decision nobody made.
        $this->withCookie('as_is_newsletter_prompt', '1')
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('newsletter-prompt-title');
    });

    it('does not interrupt a signed-in customer', function (): void {
        // A customer who already has an account has already told us who they
        // are. Being asked to also hand over an address is asking twice, and on
        // a screen they may be reading an order.
        $this->actingAs(bidder())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('newsletter-prompt-title');
    });

    it('records the modal as where the signup came from', function (): void {
        Livewire::test(Signup::class)
            ->set('email', 'ama@example.com')
            ->call('requestConfirmation')
            ->assertHasNoErrors();

        expect(NewsletterSubscriber::sole()->source)->toBe('modal');
    });

    it('does not promise a sending schedule it cannot keep', function (): void {
        // Nothing in this project can send a newsletter yet. If the copy starts
        // promising a frequency, the promise has outrun the code -- and these are
        // the words a customer would hold us to.
        $html = $this->get(route('home'))->assertOk()->getContent();

        foreach (['weekly', 'every week', 'monthly', 'every month', 'twice a'] as $cadence) {
            expect($html)->not->toContain($cadence);
        }
    });

    it('does not offer a signup form inside the admin screen', function (): void {
        $admin = userWithRole('admin');

        $response = $this->actingAs($admin)
            ->get(route('admin.newsletter.index'))
            ->assertOk();

        // Two separate guarantees, and they used to be different tests. The
        // modal is suppressed for signed-in visitors, so staff never get it at
        // all. And the admin screen's own content does not present itself as a
        // place to subscribe or unsubscribe anyone. What matters is that an
        // operator managing the list is not also being asked to join it.
        expect($response->getContent())->not->toContain('newsletter-prompt-title');
        expect(pageMainContent($response))
            ->not->toContain('id="newsletter-email"')
            ->not->toContain('requestConfirmation');
    });
});

describe('newsletter privacy', function (): void {

    it('does not render the address on the confirmation page', function (): void {
        $subscriber = NewsletterSubscriber::factory()->create(['email' => 'kwame@example.com']);

        $this->get($subscriber->confirmationUrl())
            ->assertOk()
            ->assertDontSee('kwame@example.com');
    });

    it('does not render the address on the unsubscribe page', function (): void {
        $subscriber = NewsletterSubscriber::factory()->subscribed()->create(['email' => 'kwame@example.com']);

        $this->get($subscriber->unsubscribeUrl())
            ->assertOk()
            ->assertDontSee('kwame@example.com');
    });

    it('does not store an IP address alongside the subscription', function (): void {
        $this->assertFalse(
            Schema::hasColumn('newsletter_subscribers', 'ip_address'),
            'The migration notes a deliberate decision not to collect IP addresses; this test fails if one is added without saying so.',
        );
    });
});

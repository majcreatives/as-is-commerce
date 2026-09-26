<?php

declare(strict_types=1);

use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Referrals\Services\ReferralCodes;
use App\Domain\Referrals\Services\ReferralProgramme;
use App\Domain\Referrals\Services\ReferralReconciler;
use App\Enums\ReferralStatus;
use App\Livewire\Admin\Referrals\ReferralQueue;
use App\Models\CreditTransaction;
use App\Models\Referral;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * The two things standing between a dark referral programme and credits.
 *
 * Both were added because the alternative was leaving a known hole in a
 * programme that is about to be switched on, and neither is a cap:
 *
 *   The attribution window stops a link paying out years after it was shared.
 *   Ring detection catches A introducing B and B introducing A, which the
 *                            per-referrer cap cannot see and no single row
 *                            looks wrong.
 *
 * The window refuses. Ring detection only reports, and these tests pin that
 * distinction down, because a detector that quietly became a blocker would be
 * a business rule nobody approved.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_abuse']);

    settings()->set('referrals_enabled', true);
    settings()->set('referral_reward_credits', 10);
    settings()->set('referral_max_rewards_per_referrer', 20);
    settings()->set('referral_attribution_window_days', 30);

    $this->programme = app(ReferralProgramme::class);
    $this->reconciler = app(ReferralReconciler::class);
});

// ============================================================ The window

it('rewards a referral that converts inside the window', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    expect(Referral::first()->status)->toBe(ReferralStatus::Rewarded)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(10);
});

it('rewards nothing when the purchase lands after the window closes', function (): void {
    [$referrer, $joiner, $referral] = referralPair();

    // Signup today, purchase in three months' time. The referral relationship
    // is real and the purchase is genuine; the pair is simply too old to have
    // been anybody's introduction any more.
    $this->travel(31)->days();

    qualifyingPurchase($joiner);

    $fresh = $referral->fresh();

    expect($fresh->status)->toBe(ReferralStatus::Invalidated)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0)
        ->and(CreditTransaction::count())->toBe(0);
});

it('says why a referral lapsed, and names the window that was applied', function (): void {
    [, $joiner, $referral] = referralPair();

    $this->travel(31)->days();

    qualifyingPurchase($joiner);

    $fresh = $referral->fresh();

    // The row explains itself. "Not eligible" with no reason would be an
    // administrator's question with no answer attached.
    expect($fresh->invalidation_reason)
        ->toContain('30-day')
        ->toContain('attribution window')
        // A rule, not a person: no admin is named on a decision nobody made.
        ->and($fresh->invalidated_by)->toBeNull()
        ->and($fresh->invalidated_at)->not->toBeNull();
});

it('still rewards on the last day of the window', function (): void {
    [$referrer, $joiner] = referralPair();

    // The boundary is the point where the test is worth writing: an off-by-one
    // here either pays a referral a day late or refuses one a day early, and
    // neither is visible to anybody reading the settings screen.
    $this->travel(30)->days();

    qualifyingPurchase($joiner);

    expect(Referral::first()->status)->toBe(ReferralStatus::Rewarded)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(10);
});

it('does not let a later purchase retry a lapsed referral', function (): void {
    [, $joiner, $referral] = referralPair();

    $this->travel(31)->days();
    qualifyingPurchase($joiner);

    expect($referral->fresh()->status)->toBe(ReferralStatus::Invalidated);

    // A second, larger purchase, well inside no window at all. Lapsed is
    // terminal: waiting does not make the signup younger, and a customer who
    // shops again must not be a way to resurrect an old referral.
    $this->travel(1)->days();
    qualifyingPurchase($joiner);

    expect($referral->fresh()->status)->toBe(ReferralStatus::Invalidated)
        ->and(CreditTransaction::where('type', 'referral_credit')->count())->toBe(0);
});

it('leaves a referral alone when no window is configured', function (): void {
    settings()->set('referral_attribution_window_days', 0);

    [$referrer, $joiner] = referralPair();

    $this->travel(5)->years();

    qualifyingPurchase($joiner);

    // Zero means no window, deliberately and explicitly. An administrator who
    // wants the rule switched off says so; nobody gets it by default.
    expect(Referral::first()->status)->toBe(ReferralStatus::Rewarded)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(10);
});

it('reads the window as days from the signup, not from today', function (): void {
    [$referrer, $joiner, $referral] = referralPair();

    $expires = $this->programme->attributionExpiresAt($referral->fresh());

    expect($expires)->not->toBeNull()
        ->and($expires->toDateString())
        ->toBe($referral->fresh()->attributed_at->copy()->addDays(30)->toDateString());
});

it('reports the window to the customer rather than failing silently', function (): void {
    [, , $referral] = referralPair();

    // A rule nobody can see is a rule nobody can plan around. The dashboard
    // gets to say how long somebody has.
    expect($this->programme->attributionExpiresAt($referral->fresh()))
        ->toBeInstanceOf(DateTimeInterface::class)
        ->and($this->programme->withinAttributionWindow($referral->fresh()))->toBeTrue();
});

it('does not disturb a reward that was already granted', function (): void {
    [$referrer, $joiner, $referral] = referralPair();

    qualifyingPurchase($joiner);

    expect($referral->fresh()->status)->toBe(ReferralStatus::Rewarded);

    // Now an administrator tightens the rule from 30 days to 1. The reward is
    // in the ledger and possibly already spent on bids; a settings change must
    // not reach back and take it.
    settings()->set('referral_attribution_window_days', 1);
    $this->travel(90)->days();

    expect($referral->fresh()->status)->toBe(ReferralStatus::Rewarded)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(10);
});

it('does not log a referral programme failure for an expected lapse', function (): void {
    Log::spy();

    [, $joiner] = referralPair();

    $this->travel(31)->days();
    qualifyingPurchase($joiner);

    // The listener swallows everything, so a lapsed referral must not arrive as
    // an exception. An error log full of this would train whoever reads it to
    // ignore it.
    Log::shouldNotHaveReceived('error');
});

// ============================================================ Rings

it('finds no ring in an honest chain of introductions', function (): void {
    // A refers B, B refers C. A straight line, not a circle.
    [$a, $b] = referralPair();
    app(AttributeReferral::class)->handle(
        bidder(0),
        app(ReferralCodes::class)->forUser($b),
    );

    $types = array_column($this->reconciler->report(), 'type');

    expect($types)->not->toContain('referral_ring');
});

it('finds a two-person ring', function (): void {
    // The smallest possible abuse: A refers B, then B refers A back. Neither
    // row is self-referential, so the CHECK that refuses self-referral never
    // fires and nothing about either record looks wrong.
    [$a, $b] = referralPair();

    app(AttributeReferral::class)->handle(
        $a->fresh(),
        app(ReferralCodes::class)->forUser($b),
    );

    $ring = array_values(array_filter(
        $this->reconciler->report(),
        fn (array $a): bool => $a['type'] === 'referral_ring',
    ));

    expect($ring)->toHaveCount(1)
        ->and($ring[0]['detail'])->toContain('closed loop')
        ->toContain('per-referrer cap does not apply');
});

it('finds a ring of three', function (): void {
    // A → B → C → A. The shape only shows up when the whole graph is walked,
    // which is why this is not a check on a single row.
    [$a, $b] = referralPair();
    $c = bidder(0);

    $attribute = app(AttributeReferral::class);
    $codes = app(ReferralCodes::class);

    $attribute->handle($c, $codes->forUser($b->fresh()));
    $attribute->handle($a->fresh(), $codes->forUser($c->fresh()));

    $ring = array_values(array_filter(
        $this->reconciler->report(),
        fn (array $anomaly): bool => $anomaly['type'] === 'referral_ring',
    ));

    expect($ring)->toHaveCount(1);
});

it('reports a ring once rather than once per member', function (): void {
    // Three rows all belong to the same ring. Three identical warnings teach an
    // administrator to scroll past them.
    [$a, $b] = referralPair();
    $c = bidder(0);

    $attribute = app(AttributeReferral::class);
    $codes = app(ReferralCodes::class);

    $attribute->handle($c, $codes->forUser($b->fresh()));
    $attribute->handle($a->fresh(), $codes->forUser($c->fresh()));

    expect(Referral::count())->toBe(3)
        ->and(array_filter($this->reconciler->report(), fn ($x): bool => $x['type'] === 'referral_ring'))
        ->toHaveCount(1);
});

it('terminates on a ring instead of walking it for ever', function (): void {
    // The failure mode of a naive recursive walk. Finding this is the whole
    // point; hanging on it would take the reconciliation command down.
    [$a, $b] = referralPair();

    app(AttributeReferral::class)->handle(
        $a->fresh(),
        app(ReferralCodes::class)->forUser($b),
    );

    $started = microtime(true);
    $this->reconciler->report();

    expect(microtime(true) - $started)->toBeLessThan(5.0);
});

it('reports a ring without stopping anybody being paid', function (): void {
    // Report, not refuse. A ring is a shape, and whether it is a family
    // sharing a phone or deliberate fraud is a question for a person. Credits
    // already granted stay granted, and an unreviewed ring is still visible in
    // the same report.
    [$a, $b] = referralPair();

    app(AttributeReferral::class)->handle(
        $a->fresh(),
        app(ReferralCodes::class)->forUser($b),
    );

    // The referred customer of the first referral buys something, so the
    // referrer is paid despite the ring.
    qualifyingPurchase($b->fresh());

    expect(creditWalletFor($a->fresh())->fresh()->balance)->toBe(10);

    $types = array_column($this->reconciler->report(), 'type');

    expect($types)->toContain('referral_ring');
});

it('lets an administrator refuse a ringed referral before it is paid', function (): void {
    // The one action that is available: invalidation before credits are
    // issued, which is a legal move out of `Attributed` and needs no clawback.
    [$a, $b] = referralPair();

    app(AttributeReferral::class)->handle(
        $a->fresh(),
        app(ReferralCodes::class)->forUser($b),
    );

    $referral = Referral::orderByDesc('id')->firstOrFail();
    $referral->status = ReferralStatus::Invalidated;
    $referral->invalidation_reason = 'Closed referral ring confirmed as abuse.';
    $referral->save();

    expect($referral->fresh()->status)->toBe(ReferralStatus::Invalidated)
        ->and($referral->fresh()->invalidation_reason)->toContain('ring');
});

it('finds no ring when nobody has been referred', function (): void {
    expect(array_column($this->reconciler->report(), 'type'))->not->toContain('referral_ring');
});

it('does not mistake a deep honest chain for a ring', function (): void {
    // Ten introductions in a line. Long enough that a detector keyed on
    // "suspiciously deep" rather than on the actual shape would fire here, and
    // a real customer's referral chain is not evidence of anything.
    $previous = bidder(0);
    $codes = app(ReferralCodes::class);
    $attribute = app(AttributeReferral::class);

    for ($i = 0; $i < 9; $i++) {
        $next = bidder(0);
        $attribute->handle($next, $codes->forUser($previous->fresh()));
        $previous = $next;
    }

    expect(Referral::count())->toBe(9)
        ->and(array_column($this->reconciler->report(), 'type'))->not->toContain('referral_ring');
});

it('finds a ring even when it closed long before the report runs', function (): void {
    // Detection is a property of the graph, not of a moment. A ring that formed
    // last year is still a ring today, and the report has to see it whether or
    // not anything happened while it sat there.
    [$a, $b] = referralPair();

    app(AttributeReferral::class)->handle(
        $a->fresh(),
        app(ReferralCodes::class)->forUser($b),
    );

    $this->travel(200)->days();

    expect(array_column($this->reconciler->report(), 'type'))->toContain('referral_ring');
});

// ============================================================ What staff see

it('shows a ring to staff who can act on referrals', function (): void {
    [$a, $b] = referralPair();

    app(AttributeReferral::class)->handle(
        $a->fresh(),
        app(ReferralCodes::class)->forUser($b),
    );

    Livewire::actingAs(referralStaff(['referrals.view', 'referrals.manage']))
        ->test(ReferralQueue::class)
        ->assertOk()
        ->assertSee('referral_ring')
        // Said in the words an administrator can act on, not just a code.
        ->assertSee('closed loop');
});

it('hides a ring from staff who may only look', function (): void {
    // The report is only shown to somebody who can act on it. A read-only view
    // of a finding with no way to act on it is a notification, not a control.
    [$a, $b] = referralPair();

    app(AttributeReferral::class)->handle(
        $a->fresh(),
        app(ReferralCodes::class)->forUser($b),
    );

    Livewire::actingAs(referralStaff(['referrals.view']))
        ->test(ReferralQueue::class)
        ->assertOk()
        ->assertDontSee('referral_ring');
});

it('tells a lapsed referral apart from a refused one in the summary', function (): void {
    // Both end at `Invalidated`, and the difference matters: one expired on a
    // rule and the other was refused by a person. An administrator reading
    // "not eligible" has to be able to tell which they are looking at.
    [, $joiner, $referral] = referralPair();

    $this->travel(31)->days();
    qualifyingPurchase($joiner);

    expect($referral->fresh()->status->label())->toBe('Not eligible')
        ->and($referral->fresh()->invalidation_reason)->toContain('window');
});

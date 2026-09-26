<?php

declare(strict_types=1);

use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Referrals\Actions\RewardReferral;
use App\Domain\Referrals\Exceptions\ReferralNotAllowed;
use App\Domain\Referrals\Services\ReferralCodes;
use App\Domain\Referrals\Services\ReferralReconciler;
use App\Enums\CreditTransactionType;
use App\Enums\ReferralStatus;
use App\Livewire\Account\ReferralDashboard;
use App\Livewire\Admin\Referrals\ReferralQueue;
use App\Models\CreditTransaction;
use App\Models\Referral;
use Livewire\Livewire;

/*
 * Who can see what, and what nobody can do.
 *
 * A referral programme is where a platform gives away money, so every one of
 * these calls a method directly rather than looking for a button: a control
 * that is not rendered is not a rule.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    settings()->set('referrals_enabled', true);
    settings()->set('referral_reward_credits', 50);

    $this->codes = app(ReferralCodes::class);
    $this->attribute = app(AttributeReferral::class);
});

/**
 * A member of staff holding exactly the named permissions and nothing else.
 */
// ------------------------------------------------------------ Privacy

it('shows a customer only their own referrals', function (): void {
    $mine = bidder(0);
    $theirs = bidder(0);

    $this->attribute->handle(bidder(0), $this->codes->forUser($mine));
    $this->attribute->handle(bidder(0), $this->codes->forUser($theirs));

    Livewire::actingAs($mine)
        ->test(ReferralDashboard::class)
        ->assertViewHas('joined', 1);
});

/*
 * A referrer knows they invited people. Which of them bought something, and
 * who they are, is that person's business.
 */
it('never names a referred customer to their referrer', function (): void {
    $referrer = bidder(0);
    $joiner = bidder(0);
    $joiner->update(['name' => 'Kwame Mensah', 'email' => 'kwame@example.test']);

    $this->attribute->handle($joiner->fresh(), $this->codes->forUser($referrer));

    Livewire::actingAs($referrer)
        ->test(ReferralDashboard::class)
        ->assertSee('Someone joined')
        ->assertDontSee('Kwame')
        ->assertDontSee('kwame@example.test')
        ->assertDontSee($joiner->phone);
});

it('does not show a customer another customer referral code', function (): void {
    $other = bidder(0);
    $theirCode = $this->codes->forUser($other);

    Livewire::actingAs(bidder(0))
        ->test(ReferralDashboard::class)
        ->assertDontSee($theirCode);
});

it('keeps the referral page behind authentication', function (): void {
    $this->get(route('referrals.index'))->assertRedirect(route('login'));
});

// ----------------------------------------------------- Nothing to forge

/*
 * Structural, and the point of it. There is nothing on the customer's page a
 * request could set that would create credits, name a referrer, or nominate a
 * qualifying order.
 */
it('exposes nothing a browser could use to fabricate a reward', function (): void {
    $forbidden = [
        'reward', 'rewardCredits', 'credits', 'amount',
        'referrerId', 'referrer_user_id', 'qualifyingOrderId', 'qualifying_order_id',
    ];

    foreach ($forbidden as $property) {
        expect(property_exists(ReferralDashboard::class, $property))
            ->toBeFalse("The referral page must not expose a {$property} property.");
    }

    foreach (['reward', 'grant', 'issueCredits', 'qualify', 'attribute'] as $method) {
        expect(method_exists(ReferralDashboard::class, $method))
            ->toBeFalse("The referral page must not expose {$method}.");
    }
});

it('gives a customer no way to nominate a referrer after registration', function (): void {
    $referrer = bidder(0);
    $other = bidder(0);
    $joiner = bidder(0);

    $this->attribute->handle($joiner, $this->codes->forUser($referrer));

    // Trying again with a different code changes nothing at all.
    $this->attribute->handle($joiner->fresh(), $this->codes->forUser($other));

    expect(Referral::count())->toBe(1)
        ->and(Referral::first()->referrer_user_id)->toBe($referrer->id);
});

it('gives a customer no way to reward themselves', function (): void {
    $referrer = bidder(0);
    $joiner = bidder(0);
    $referral = $this->attribute->handle($joiner, $this->codes->forUser($referrer));

    // The action refuses a referral that has no qualifying purchase, whoever
    // calls it.
    expect(fn (): Referral => app(RewardReferral::class)->reward($referral))
        ->toThrow(ReferralNotAllowed::class);

    expect(creditWalletFor($referrer)->fresh()->balance)->toBe(0)
        ->and(CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->count())->toBe(0);
});

// --------------------------------------------------------- Admin access

it('keeps a customer out of the admin referral screen', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('admin.referrals'))
        ->assertForbidden();
});

it('forbids a customer from the admin referral component', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(ReferralQueue::class)
        ->assertForbidden();
});

it('grants a customer none of the referral permissions', function (): void {
    $customer = userWithRole('customer');

    foreach (['referrals.view', 'referrals.manage', 'referrals.settings'] as $permission) {
        expect($customer->can($permission))->toBeFalse("A customer must not hold {$permission}.");
    }
});

it('refuses to invalidate a referral without the manage permission', function (): void {
    $referral = $this->attribute->handle(bidder(0), $this->codes->forUser(bidder(0)));

    $viewer = referralStaff(['referrals.view']);

    Livewire::actingAs($viewer)
        ->test(ReferralQueue::class)
        ->call('startInvalidating', $referral->id)
        ->assertForbidden();

    expect($referral->fresh()->status)->toBe(ReferralStatus::Attributed);
});

/*
 * The admin screen exists to look and to refuse. It cannot create credits,
 * decide what a reward is worth, or say a purchase qualified.
 */
it('offers staff no way to grant credits or fabricate a qualification', function (): void {
    foreach ([
        'grantReward', 'issueReward', 'award', 'setRewardAmount',
        'markQualified', 'qualify', 'reassign', 'setReferrer', 'delete',
    ] as $method) {
        expect(method_exists(ReferralQueue::class, $method))
            ->toBeFalse("The admin referral screen must not expose {$method}.");
    }
});

it('lets staff refuse a referral before it is paid, with a reason', function (): void {
    $referral = $this->attribute->handle(bidder(0), $this->codes->forUser(bidder(0)));

    Livewire::actingAs(userWithRole('admin'))
        ->test(ReferralQueue::class)
        ->call('startInvalidating', $referral->id)
        ->set('invalidationReason', 'Duplicate account.')
        ->call('invalidate')
        ->assertHasNoErrors();

    $refused = $referral->fresh();

    expect($refused->status)->toBe(ReferralStatus::Invalidated)
        ->and($refused->invalidation_reason)->toBe('Duplicate account.')
        ->and($refused->invalidated_by)->not->toBeNull()
        // Preserved, not deleted: somebody may have to explain this later.
        ->and(Referral::count())->toBe(1);
});

it('will not refuse a referral without a reason', function (): void {
    $referral = $this->attribute->handle(bidder(0), $this->codes->forUser(bidder(0)));

    Livewire::actingAs(userWithRole('admin'))
        ->test(ReferralQueue::class)
        ->call('startInvalidating', $referral->id)
        ->call('invalidate')
        ->assertHasErrors('invalidation');

    expect($referral->fresh()->status)->toBe(ReferralStatus::Attributed);
});

/*
 * After credits are issued, a status change here would not take them back --
 * they may already be spent on bids that cannot be unwound. Offering the
 * control would imply otherwise.
 */
it('refuses to invalidate a referral that has already been rewarded', function (): void {
    $referrer = bidder(0);
    $joiner = bidder(0);
    $this->attribute->handle($joiner, $this->codes->forUser($referrer));

    qualifyingPurchase($joiner->fresh());
    $referral = Referral::first();

    expect($referral->status)->toBe(ReferralStatus::Rewarded);

    Livewire::actingAs(userWithRole('admin'))
        ->test(ReferralQueue::class)
        ->call('startInvalidating', $referral->id)
        ->set('invalidationReason', 'Changed my mind.')
        ->call('invalidate')
        ->assertHasErrors('invalidation');

    expect($referral->fresh()->status)->toBe(ReferralStatus::Rewarded)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(50);
});

// ------------------------------------------------------- Reconciliation

it('reports a qualified referral that was never rewarded', function (): void {
    settings()->set('referrals_enabled', false);

    $referrer = bidder(0);
    $joiner = bidder(0);
    $this->attribute->handle($joiner, $this->codes->forUser($referrer));

    qualifyingPurchase($joiner->fresh());

    $anomalies = app(ReferralReconciler::class)->report();

    expect(collect($anomalies)->pluck('type'))->toContain('qualified_not_rewarded');
});

it('reports nothing when everything adds up', function (): void {
    $referrer = bidder(0);
    $joiner = bidder(0);
    $this->attribute->handle($joiner, $this->codes->forUser($referrer));

    qualifyingPurchase($joiner->fresh());

    expect(app(ReferralReconciler::class)->report())->toBe([]);
});

/*
 * The single most important property of the reconciliation command: it must
 * never issue a credit, however wrong things look.
 */
it('repairs nothing and issues no credits', function (): void {
    settings()->set('referrals_enabled', false);

    $referrer = bidder(0);
    $joiner = bidder(0);
    $this->attribute->handle($joiner, $this->codes->forUser($referrer));

    qualifyingPurchase($joiner->fresh());

    $this->artisan('referrals:reconcile')->assertFailed();

    // Reported, and unchanged. A command that granted what it thought was
    // missing would mint credits on every run.
    expect(Referral::first()->status)->toBe(ReferralStatus::Qualified)
        ->and(creditWalletFor($referrer)->fresh()->balance)->toBe(0)
        ->and(CreditTransaction::where('type', CreditTransactionType::ReferralCredit)->count())->toBe(0);
});

it('exits cleanly when there is nothing to report', function (): void {
    $this->artisan('referrals:reconcile')->assertSuccessful();
});

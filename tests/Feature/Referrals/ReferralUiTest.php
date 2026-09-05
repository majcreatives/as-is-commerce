<?php

declare(strict_types=1);

use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Referrals\Services\ReferralCodes;
use App\Livewire\Account\Dashboard;
use App\Livewire\Account\ReferralDashboard;
use App\Livewire\Admin\Referrals\ReferralQueue;
use App\Models\Referral;
use Livewire\Livewire;

/*
 * What the referral screens say, and what they must never say.
 *
 * The wording rules: a reward is a count of credits and never a cedis figure;
 * nothing promises income; and the material conditions are on the page rather
 * than in small print somewhere else.
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

// ------------------------------------------------- The customer's page

it('gives a customer a code and a link to share', function (): void {
    $user = bidder(0);

    $component = Livewire::actingAs($user)->test(ReferralDashboard::class)->assertOk();

    $code = $user->fresh()->referral_code;

    expect($code)->not->toBeNull();

    $component->assertSee($code)
        ->assertSee('Copy link')
        // The link points at registration, which is where attribution happens.
        ->assertSee('ref='.$code, escape: false);
});

it('shows an empty state before anybody has joined', function (): void {
    Livewire::actingAs(bidder(0))
        ->test(ReferralDashboard::class)
        ->assertSee('No one yet');
});

it('states every material condition on the page', function (): void {
    Livewire::actingAs(bidder(0))
        ->test(ReferralDashboard::class)
        // What qualifies, said plainly.
        ->assertSee('first purchase')
        ->assertSee('A signup on its own does not earn anything')
        // The anti-abuse rules.
        ->assertSee('cannot refer yourself')
        // And what the reward actually is.
        ->assertSee('not money')
        ->assertSee('cannot be withdrawn');
});

it('promises no income anywhere', function (): void {
    $component = Livewire::actingAs(bidder(0))->test(ReferralDashboard::class);

    foreach (['earn money', 'guaranteed', 'unlimited', 'get paid', 'cash back', 'commission'] as $claim) {
        $component->assertDontSee($claim, escape: false);
    }
});

it('shows a reward as credits and never as cedis', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    Livewire::actingAs($referrer)
        ->test(ReferralDashboard::class)
        ->assertSee('Credits earned')
        ->assertSee('50')
        ->assertSee('Reward received')
        // A referral reward is not a payout, so there is no cedis figure.
        ->assertDontSee('GH₵');
});

it('shows the cap when one is configured', function (): void {
    settings()->set('referral_max_rewards_per_referrer', 5);

    Livewire::actingAs(bidder(0))
        ->test(ReferralDashboard::class)
        ->assertSee('up to 5 people');
});

it('says plainly when the programme is paused', function (): void {
    settings()->set('referrals_enabled', false);

    Livewire::actingAs(bidder(0))
        ->test(ReferralDashboard::class)
        ->assertSee('Referral rewards are paused');
});

// ------------------------------------------------------- The dashboard

it('shows a modest referral card on the dashboard', function (): void {
    Livewire::actingAs(bidder(0))
        ->test(Dashboard::class)
        ->assertSee('Invite friends')
        ->assertSee('Your referral link');
});

it('shows earned credits on the dashboard once somebody has bought something', function (): void {
    [$referrer, $joiner] = referralPair();

    qualifyingPurchase($joiner);

    Livewire::actingAs($referrer)
        ->test(Dashboard::class)
        // Rendered by x-credits, which the rest of the customer interface uses
        // for body copy. Capitalised "Credits" is the notification convention.
        ->assertSee('50 credits');
});

// ---------------------------------------------------------- The admin

it('shows staff the referral queue and its counts', function (): void {
    [$referrer, $joiner] = referralPair();
    qualifyingPurchase($joiner);

    Livewire::actingAs(userWithRole('admin'))
        ->test(ReferralQueue::class)
        ->assertOk()
        ->assertSee('Credits issued')
        ->assertSee($referrer->name)
        ->assertSee('Rewarded');
});

it('lets staff trace a reward to its order and ledger row', function (): void {
    [, $joiner] = referralPair();
    $order = qualifyingPurchase($joiner);

    $referral = Referral::first();

    Livewire::actingAs(userWithRole('admin'))
        ->test(ReferralQueue::class)
        // The qualifying order, and the ledger row the credits came in on.
        ->assertSee($order->order_number)
        ->assertSee('ledger #'.$referral->credit_transaction_id);
});

it('filters referrals by status', function (): void {
    [, $joiner] = referralPair();
    qualifyingPurchase($joiner);

    // A second, unqualified referral.
    $waiting = bidder(0);
    $this->attribute->handle($waiting, $this->codes->forUser(bidder(0)));

    Livewire::actingAs(userWithRole('admin'))
        ->test(ReferralQueue::class)
        ->set('filter', 'rewarded')
        ->assertViewHas('referrals', fn ($page): bool => $page->total() === 1);
});

it('tells staff that nothing on the screen can grant credits', function (): void {
    Livewire::actingAs(userWithRole('admin'))
        ->test(ReferralQueue::class)
        // Phrases short enough not to span a line wrap in the rendered HTML.
        ->assertSee('never cash')
        ->assertSee('through the credit ledger');
});

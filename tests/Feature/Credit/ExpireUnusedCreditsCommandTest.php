<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/*
 * The sweep that reconciles expired promotional credits.
 *
 * Purchased credits do not expire by default; promotional credits may. The
 * ledger balance keeps counting an expired lot's remainder until an
 * EXPIRATION transaction is posted, so this command is what finally writes
 * the unspent part off. It invents no expiry policy -- it only acts on lots
 * whose own `expires_at` has passed, by server time.
 */

beforeEach(function (): void {
    seedRoles();
});

it('writes off the unspent remainder of expired lots', function (): void {
    $user = User::factory()->create();
    grantCredits($user, 60, CreditTransactionType::PromotionalCredit, now()->subDay());
    grantCredits($user, 40, CreditTransactionType::Purchase);

    $this->artisan('credits:expire-unused')->assertSuccessful();

    $transactions = CreditTransaction::where('type', CreditTransactionType::Expiration)->get();

    expect($transactions)->toHaveCount(1)
        ->and($transactions->first()->amount)->toBe(-60)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(40);
});

it('never writes off lots that have not expired', function (): void {
    $user = User::factory()->create();
    grantCredits($user, 50, CreditTransactionType::PromotionalCredit, now()->addDay());
    grantCredits($user, 70, CreditTransactionType::Purchase);

    $this->artisan('credits:expire-unused')->assertSuccessful();

    expect(CreditTransaction::where('type', CreditTransactionType::Expiration)->count())->toBe(0)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(120);
});

it('does nothing at all when no credits have expired', function (): void {
    $user = User::factory()->create();
    grantCredits($user, 100);

    $this->artisan('credits:expire-unused')
        ->expectsOutputToContain('Wrote off unused credits for 0 wallet(s).')
        ->assertSuccessful();

    expect(creditWalletFor($user)->fresh()->balance)->toBe(100);
});

it('writes each wallet off once when run repeatedly', function (): void {
    $user = User::factory()->create();
    grantCredits($user, 60, CreditTransactionType::PromotionalCredit, now()->subDay());

    foreach (range(1, 3) as $ignored) {
        $this->artisan('credits:expire-unused')->assertSuccessful();
    }

    // One expiration, not three.
    expect(CreditTransaction::where('type', CreditTransactionType::Expiration)->count())->toBe(1)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(0);
});

it('expires the credits of more than one wallet in a pass', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    grantCredits($first, 30, CreditTransactionType::PromotionalCredit, now()->subDay());
    grantCredits($second, 20, CreditTransactionType::PromotionalCredit, now()->subDay());

    $this->artisan('credits:expire-unused')->assertSuccessful();

    expect(CreditTransaction::where('type', CreditTransactionType::Expiration)->count())->toBe(2)
        ->and(creditWalletFor($first)->fresh()->balance)->toBe(0)
        ->and(creditWalletFor($second)->fresh()->balance)->toBe(0);
});

it('honours the limit it is given', function (): void {
    $users = collect(range(1, 3))->map(function (): User {
        $user = User::factory()->create();
        grantCredits($user, 10, CreditTransactionType::PromotionalCredit, now()->subDay());

        return $user;
    });

    $this->artisan('credits:expire-unused', ['--limit' => 1])->assertSuccessful();

    expect(CreditTransaction::where('type', CreditTransactionType::Expiration)->count())->toBe(1)
        ->and($users)->toHaveCount(3);
});

it('stamps the scheduler heartbeat after a pass', function (): void {
    $user = User::factory()->create();
    grantCredits($user, 10, CreditTransactionType::PromotionalCredit, now()->subDay());

    $this->artisan('credits:expire-unused')->assertSuccessful();

    expect(Cache::has('sweeps:expire_unused:last_run'))->toBeTrue();
});

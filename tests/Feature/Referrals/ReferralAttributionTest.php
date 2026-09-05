<?php

declare(strict_types=1);

use App\Domain\Referrals\Actions\AttributeReferral;
use App\Domain\Referrals\Exceptions\ReferralNotAllowed;
use App\Domain\Referrals\Services\ReferralCodes;
use App\Enums\ReferralStatus;
use App\Livewire\Auth\Register;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Who introduced whom, and the rules that keep that honest.
 *
 * Attribution is not a reward: a row here means somebody signed up through a
 * link, and nothing more. Everything below is about making sure the
 * relationship is real, unique, and impossible to reassign.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->codes = app(ReferralCodes::class);
    $this->attribute = app(AttributeReferral::class);
});

// ------------------------------------------------------------ The code

it('issues a customer a referral code on demand', function (): void {
    $user = userWithRole('customer');

    expect($user->referral_code)->toBeNull();

    $code = $this->codes->forUser($user);

    expect($code)->toHaveLength(8)
        ->and($user->fresh()->referral_code)->toBe($code);
});

it('gives one customer the same code every time', function (): void {
    $user = userWithRole('customer');

    $first = $this->codes->forUser($user);
    $second = $this->codes->forUser($user->fresh());

    // A link shared last month has to keep working.
    expect($second)->toBe($first);
});

it('gives different customers different codes', function (): void {
    $codes = collect(range(1, 25))
        ->map(fn (): string => $this->codes->forUser(userWithRole('customer')));

    expect($codes->unique())->toHaveCount(25);
});

/*
 * A sequential code would let anybody walk the customer base by counting, and
 * one built from a phone number would put personal data in a shared URL.
 */
it('uses codes that cannot be enumerated or traced to a person', function (): void {
    $user = userWithRole('customer');
    $user->update(['name' => 'Ama Boateng', 'email' => 'ama@example.test']);

    $code = $this->codes->forUser($user->fresh());

    expect($code)->not->toContain((string) $user->id)
        ->and($code)->not->toContain('AMA')
        ->and($code)->not->toContain(mb_substr($user->phone, -4))
        // The alphabet excludes characters that look alike when written down.
        ->and($code)->not->toMatch('/[01ILO]/');
});

it('refuses to let two customers hold one code', function (): void {
    $first = userWithRole('customer');
    $code = $this->codes->forUser($first);

    expect(fn () => DB::table('users')
        ->where('id', userWithRole('customer')->id)
        ->update(['referral_code' => $code]))
        ->toThrow(QueryException::class);
});

it('resolves a code however it was typed', function (): void {
    $owner = userWithRole('customer');
    $code = $this->codes->forUser($owner);

    expect($this->codes->owner(mb_strtolower($code))?->id)->toBe($owner->id)
        ->and($this->codes->owner(" {$code} ")?->id)->toBe($owner->id)
        ->and($this->codes->owner('NOTACODE'))->toBeNull()
        ->and($this->codes->owner(null))->toBeNull();
});

// ------------------------------------------------------- Attribution

it('records who introduced a new customer', function (): void {
    $referrer = userWithRole('customer');
    $code = $this->codes->forUser($referrer);
    $joiner = userWithRole('customer');

    $referral = $this->attribute->handle($joiner, $code);

    expect($referral)->not->toBeNull()
        ->and($referral->referrer_user_id)->toBe($referrer->id)
        ->and($referral->referred_user_id)->toBe($joiner->id)
        ->and($referral->code_used)->toBe($code)
        // Signed up, and nothing more. No credits exist at this point.
        ->and($referral->status)->toBe(ReferralStatus::Attributed)
        ->and($referral->reward_credits)->toBeNull()
        ->and($referral->credit_transaction_id)->toBeNull();
});

it('attributes a referral through the registration form', function (): void {
    $referrer = userWithRole('customer');
    $code = $this->codes->forUser($referrer);

    Livewire::test(Register::class, ['ref' => $code])
        ->set('name', 'New Person')
        ->set('phone', '0244000111')
        ->set('password', 'correct-horse-battery-7')
        ->set('password_confirmation', 'correct-horse-battery-7')
        ->call('register');

    $referral = Referral::first();

    expect($referral)->not->toBeNull()
        ->and($referral->referrer_user_id)->toBe($referrer->id);
});

/*
 * A mistyped link must never stop somebody creating an account.
 */
it('registers somebody normally when the code is wrong', function (): void {
    Livewire::test(Register::class, ['ref' => 'GARBAGE1'])
        ->set('phone', '0244000222')
        ->set('password', 'correct-horse-battery-7')
        ->set('password_confirmation', 'correct-horse-battery-7')
        ->call('register')
        ->assertHasNoErrors();

    expect(User::where('phone', '+233244000222')->exists())->toBeTrue()
        ->and(Referral::count())->toBe(0);
});

it('attributes nothing for an absent or malformed code', function (?string $code): void {
    $joiner = userWithRole('customer');

    expect($this->attribute->handle($joiner, $code))->toBeNull()
        ->and(Referral::count())->toBe(0);
})->with([null, '', 'x', 'WAY-TOO-LONG-FOR-A-CODE', '!!!!!!!!']);

// --------------------------------------------------------- Self-referral

/*
 * The most obvious abuse there is, refused in the application and again by a
 * CHECK constraint.
 */
it('refuses a self-referral', function (): void {
    $user = userWithRole('customer');
    $code = $this->codes->forUser($user);

    expect($this->attribute->handle($user->fresh(), $code))->toBeNull()
        ->and(Referral::count())->toBe(0);
});

it('refuses a self-referral at the database too', function (): void {
    $user = userWithRole('customer');

    expect(fn () => DB::table('referrals')->insert([
        'referrer_user_id' => $user->id,
        'referred_user_id' => $user->id,
        'code_used' => 'SELFREF1',
        'status' => 'attributed',
        'attributed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ------------------------------------------------------- One referrer

it('gives a customer one referrer for ever', function (): void {
    $first = userWithRole('customer');
    $second = userWithRole('customer');
    $joiner = userWithRole('customer');

    $this->attribute->handle($joiner, $this->codes->forUser($first));

    // A second attempt with somebody else's code changes nothing.
    $this->attribute->handle($joiner->fresh(), $this->codes->forUser($second));

    expect(Referral::count())->toBe(1)
        ->and(Referral::first()->referrer_user_id)->toBe($first->id);
});

it('refuses a second referral row at the database', function (): void {
    $first = userWithRole('customer');
    $second = userWithRole('customer');
    $joiner = userWithRole('customer');

    $this->attribute->handle($joiner, $this->codes->forUser($first));

    expect(fn () => DB::table('referrals')->insert([
        'referrer_user_id' => $second->id,
        'referred_user_id' => $joiner->id,
        'code_used' => 'SECOND01',
        'status' => 'attributed',
        'attributed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

/*
 * The relationship is a historical fact. Not merely discouraged from changing
 * -- impossible to change, by a trigger, from any code path at all.
 */
it('refuses to let a referral be reassigned', function (): void {
    $referrer = userWithRole('customer');
    $other = userWithRole('customer');
    $joiner = userWithRole('customer');

    $referral = $this->attribute->handle($joiner, $this->codes->forUser($referrer));

    expect(fn () => DB::table('referrals')->where('id', $referral->id)
        ->update(['referrer_user_id' => $other->id]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('referrals')->where('id', $referral->id)
        ->update(['referred_user_id' => $other->id]))
        ->toThrow(QueryException::class);
});

it('tells an administrative caller why an attribution failed', function (): void {
    $user = userWithRole('customer');
    $code = $this->codes->forUser($user);

    expect(fn (): Referral => $this->attribute->handleOrFail($user->fresh(), $code))
        ->toThrow(ReferralNotAllowed::class, 'cannot refer yourself');

    expect(fn (): Referral => $this->attribute->handleOrFail(userWithRole('customer'), 'NOTREAL1'))
        ->toThrow(ReferralNotAllowed::class, 'not recognised');
});

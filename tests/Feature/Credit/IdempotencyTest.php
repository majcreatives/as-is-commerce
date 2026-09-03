<?php

declare(strict_types=1);

use App\Domain\Credit\Actions\AdjustCredits;
use App\Domain\Shared\Idempotency\ConcurrentOperationInProgress;
use App\Domain\Shared\Idempotency\IdempotencyGuard;
use App\Domain\Shared\Idempotency\RetryableIdempotentOperation;
use App\Enums\CreditTransactionType;
use App\Enums\IdempotencyStatus;
use App\Models\CreditTransaction;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    seedPermissions();

    $this->guard = app(IdempotencyGuard::class);
    $this->user = User::factory()->create();
    $this->admin = userWithRole('admin');
});

// ------------------------------------------------------------- The guarantee

/*
 * The scenario from the specification: a payment provider sends the same
 * event twice. The customer must receive 100 credits, not 200.
 */
it('applies an operation once even when it is submitted twice', function (): void {
    $key = 'provider-event-abc123';

    $first = app(AdjustCredits::class)->handle(
        user: $this->user, amount: 100, reason: 'Credit purchase settled.',
        actor: $this->admin, idempotencyKey: $key,
    );

    $second = app(AdjustCredits::class)->handle(
        user: $this->user, amount: 100, reason: 'Credit purchase settled.',
        actor: $this->admin, idempotencyKey: $key,
    );

    expect($second->id)->toBe($first->id)
        ->and(creditWalletFor($this->user)->fresh()->balance)->toBe(100)
        ->and(CreditTransaction::count())->toBe(1)
        ->and(IdempotencyKey::count())->toBe(1);
});

it('replays the original result rather than recomputing', function (): void {
    $calls = 0;

    $work = function () use (&$calls): array {
        $calls++;

        return ['value' => 'computed once'];
    };

    $first = $this->guard->execute('test.op', 'key-1', null, $work);
    $second = $this->guard->execute('test.op', 'key-1', null, $work);

    expect($calls)->toBe(1)
        ->and($second)->toBe($first)
        ->and($second['value'])->toBe('computed once');
});

it('treats different keys as different operations', function (): void {
    app(AdjustCredits::class)->handle(
        user: $this->user, amount: 100, reason: 'First grant.',
        actor: $this->admin, idempotencyKey: 'key-a',
    );

    app(AdjustCredits::class)->handle(
        user: $this->user, amount: 100, reason: 'Second grant.',
        actor: $this->admin, idempotencyKey: 'key-b',
    );

    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(200)
        ->and(CreditTransaction::count())->toBe(2);
});

/*
 * Keys are scoped to their operation, so a key seen for one kind of work
 * cannot accidentally suppress a different kind.
 */
it('scopes keys to their operation', function (): void {
    $this->guard->execute('operation.one', 'shared-key', null, fn (): array => ['from' => 'one']);
    $result = $this->guard->execute('operation.two', 'shared-key', null, fn (): array => ['from' => 'two']);

    expect($result['from'])->toBe('two')
        ->and(IdempotencyKey::count())->toBe(2);
});

// -------------------------------------------------------------- Atomicity

/*
 * A failed attempt must leave nothing behind -- neither a financial effect
 * nor a claim that would block a legitimate retry.
 */
it('rolls back the claim when the work throws', function (): void {
    expect(fn () => $this->guard->execute(
        'test.op', 'failing-key', null,
        fn (): array => throw new RuntimeException('Something went wrong.'),
    ))->toThrow(RuntimeException::class);

    expect(IdempotencyKey::count())->toBe(0);
});

it('rolls back the ledger when a guarded operation throws midway', function (): void {
    expect(fn () => $this->guard->execute(
        'test.op', 'partial-key', null,
        function (): array {
            grantCredits($this->user, 100);

            throw new RuntimeException('Failed after writing.');
        },
    ))->toThrow(RuntimeException::class);

    // The whole thing rolled back: no credits, no claim.
    expect(creditWalletFor($this->user)->fresh()->balance)->toBe(0)
        ->and(CreditTransaction::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0);
});

it('allows a retry after a failure, because nothing happened', function (): void {
    try {
        $this->guard->execute('test.op', 'retry-key', null,
            fn (): array => throw new RuntimeException('Transient failure.'));
    } catch (RuntimeException) {
        // expected
    }

    $result = $this->guard->execute('test.op', 'retry-key', null, fn (): array => ['ok' => true]);

    expect($result['ok'])->toBeTrue();
});

// -------------------------------------------------------------- Contention

it('refuses to proceed while the same key is still in flight', function (): void {
    IdempotencyKey::factory()->create([
        'operation' => 'test.op',
        'idempotency_key' => 'in-flight',
        'status' => IdempotencyStatus::Pending,
    ]);

    expect(fn () => $this->guard->execute('test.op', 'in-flight', null, fn (): array => ['ok' => true]))
        ->toThrow(ConcurrentOperationInProgress::class);
});

it('releases a key whose previous attempt is recorded as failed', function (): void {
    IdempotencyKey::factory()->create([
        'operation' => 'test.op',
        'idempotency_key' => 'previously-failed',
        'status' => IdempotencyStatus::Failed,
    ]);

    expect(fn () => $this->guard->execute('test.op', 'previously-failed', null, fn (): array => ['ok' => true]))
        ->toThrow(RetryableIdempotentOperation::class);

    // The stale claim is cleared, so the caller's next attempt can proceed.
    expect(IdempotencyKey::count())->toBe(0);
});

// -------------------------------------------------------------- Constraints

/*
 * The database constraint is what makes this safe under real concurrency;
 * the application checks alone would race.
 */
it('rejects a duplicate key for the same operation at the database level', function (): void {
    IdempotencyKey::factory()->create(['operation' => 'test.op', 'idempotency_key' => 'dupe']);

    expect(fn () => IdempotencyKey::factory()->create([
        'operation' => 'test.op',
        'idempotency_key' => 'dupe',
    ]))->toThrow(QueryException::class);
});

it('allows the same key under a different operation', function (): void {
    IdempotencyKey::factory()->create(['operation' => 'op.one', 'idempotency_key' => 'dupe']);
    IdempotencyKey::factory()->create(['operation' => 'op.two', 'idempotency_key' => 'dupe']);

    expect(IdempotencyKey::count())->toBe(2);
});

it('rejects two ledger transactions sharing an idempotency key', function (): void {
    app(AdjustCredits::class)->handle(
        user: $this->user, amount: 50, reason: 'Grant.',
        actor: $this->admin, idempotencyKey: 'ledger-key',
    );

    // Bypassing the guard entirely, the unique index on the ledger still holds.
    expect(fn () => CreditTransaction::create([
        'credit_wallet_id' => creditWalletFor($this->user)->id,
        'type' => CreditTransactionType::Purchase,
        'amount' => 50,
        'balance_after' => 100,
        'idempotency_key' => 'ledger-key',
    ]))->toThrow(QueryException::class);
});

it('records the key on the ledger row it produced', function (): void {
    $transaction = app(AdjustCredits::class)->handle(
        user: $this->user, amount: 25, reason: 'Grant.',
        actor: $this->admin, idempotencyKey: 'traceable-key',
    );

    expect($transaction->idempotency_key)->toBe('traceable-key');
});

it('generates a key when the caller does not supply one', function (): void {
    app(AdjustCredits::class)->handle(
        user: $this->user, amount: 10, reason: 'Grant.', actor: $this->admin,
    );

    expect(IdempotencyKey::count())->toBe(1)
        ->and(IdempotencyKey::first()->idempotency_key)->not->toBeEmpty();
});

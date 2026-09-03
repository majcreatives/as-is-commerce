<?php

declare(strict_types=1);

use App\Domain\Credit\Services\CreditAllocationService;
use App\Enums\CreditLotSource;
use App\Models\CreditLot;

/*
 * The ordering rules on their own, without a database.
 *
 * Lots are built in memory rather than persisted, so these tests exercise the
 * decision logic alone -- if one fails, the fault is in the policy, not in a
 * query or a lock.
 */

beforeEach(function (): void {
    $this->allocator = new CreditAllocationService;
});

/**
 * An unsaved lot with just the attributes the allocator reads.
 */
function lot(int $id, CreditLotSource $source, int $remaining, ?string $expires = null): CreditLot
{
    $lot = new CreditLot([
        'source_type' => $source,
        'original_amount' => $remaining,
        'remaining_amount' => $remaining,
        'expires_at' => $expires === null ? null : now()->parse($expires),
    ]);

    $lot->id = $id;

    return $lot;
}

// ------------------------------------------------------------------ Ordering

it('puts promotional credits before purchased ones', function (): void {
    $ordered = $this->allocator->order(collect([
        lot(1, CreditLotSource::Purchased, 100),
        lot(2, CreditLotSource::Promotional, 20),
    ]));

    expect($ordered->pluck('id')->all())->toBe([2, 1]);
});

it('orders every source by its consumption priority', function (): void {
    $ordered = $this->allocator->order(collect([
        lot(1, CreditLotSource::Purchased, 10),
        lot(2, CreditLotSource::Adjustment, 10),
        lot(3, CreditLotSource::Referral, 10),
        lot(4, CreditLotSource::Promotional, 10),
    ]));

    expect($ordered->pluck('id')->all())->toBe([4, 3, 2, 1]);
});

it('puts the soonest expiry first within one source', function (): void {
    $ordered = $this->allocator->order(collect([
        lot(1, CreditLotSource::Promotional, 10, '2026-12-01'),
        lot(2, CreditLotSource::Promotional, 10, '2026-10-01'),
        lot(3, CreditLotSource::Promotional, 10, '2026-11-01'),
    ]));

    expect($ordered->pluck('id')->all())->toBe([2, 3, 1]);
});

/*
 * Credits that never expire cannot be lost by waiting, so they are kept
 * longest.
 */
it('puts expiring credits before credits that never expire', function (): void {
    $ordered = $this->allocator->order(collect([
        lot(1, CreditLotSource::Promotional, 10),
        lot(2, CreditLotSource::Promotional, 10, '2026-10-01'),
    ]));

    expect($ordered->pluck('id')->all())->toBe([2, 1]);
});

it('breaks an expiry tie with the older lot', function (): void {
    $ordered = $this->allocator->order(collect([
        lot(7, CreditLotSource::Promotional, 10, '2026-10-01'),
        lot(3, CreditLotSource::Promotional, 10, '2026-10-01'),
    ]));

    expect($ordered->pluck('id')->all())->toBe([3, 7]);
});

it('orders the worked example from the specification', function (): void {
    $ordered = $this->allocator->order(collect([
        lot(1, CreditLotSource::Purchased, 100),
        lot(2, CreditLotSource::Promotional, 10, '2026-11-01'),
        lot(3, CreditLotSource::Promotional, 10, '2026-10-01'),
    ]));

    expect($ordered->pluck('id')->all())->toBe([3, 2, 1]);
});

// ---------------------------------------------------------------- Allocation

it('takes everything from one lot when it covers the amount', function (): void {
    $allocations = $this->allocator->allocate(collect([lot(1, CreditLotSource::Purchased, 100)]), 30);

    expect($allocations)->toHaveCount(1)
        ->and($allocations[0]->lot->id)->toBe(1)
        ->and($allocations[0]->amount)->toBe(30);
});

it('spreads across lots in order when one is not enough', function (): void {
    $allocations = $this->allocator->allocate(collect([
        lot(1, CreditLotSource::Purchased, 100),
        lot(2, CreditLotSource::Promotional, 20),
    ]), 50);

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]->lot->id)->toBe(2)
        ->and($allocations[0]->amount)->toBe(20)
        ->and($allocations[1]->lot->id)->toBe(1)
        ->and($allocations[1]->amount)->toBe(30);
});

it('allocates exactly the amount requested', function (int $amount): void {
    $allocations = $this->allocator->allocate(collect([
        lot(1, CreditLotSource::Promotional, 7),
        lot(2, CreditLotSource::Referral, 13),
        lot(3, CreditLotSource::Purchased, 100),
    ]), $amount);

    expect($allocations->sum('amount'))->toBe($amount);
})->with([1, 7, 8, 20, 21, 100, 120]);

/*
 * Returning nothing rather than a partial plan matters: a caller that applied
 * half a debit would take credits without recording the full movement.
 */
it('returns no plan at all when the lots cannot cover the amount', function (): void {
    $allocations = $this->allocator->allocate(collect([
        lot(1, CreditLotSource::Promotional, 10),
        lot(2, CreditLotSource::Purchased, 10),
    ]), 21);

    expect($allocations)->toBeEmpty();
});

it('returns no plan for a non-positive amount', function (int $amount): void {
    expect($this->allocator->allocate(collect([lot(1, CreditLotSource::Purchased, 100)]), $amount))
        ->toBeEmpty();
})->with([0, -1]);

it('skips lots that are already exhausted', function (): void {
    $allocations = $this->allocator->allocate(collect([
        lot(1, CreditLotSource::Promotional, 0),
        lot(2, CreditLotSource::Purchased, 50),
    ]), 10);

    expect($allocations)->toHaveCount(1)
        ->and($allocations[0]->lot->id)->toBe(2);
});

it('sums the credit available across lots', function (): void {
    expect($this->allocator->available(collect([
        lot(1, CreditLotSource::Promotional, 20),
        lot(2, CreditLotSource::Purchased, 100),
    ])))->toBe(120);

    expect($this->allocator->available(collect()))->toBe(0);
});

it('is deterministic: the same lots always produce the same plan', function (): void {
    $lots = collect([
        lot(1, CreditLotSource::Purchased, 100),
        lot(2, CreditLotSource::Promotional, 20, '2026-10-01'),
        lot(3, CreditLotSource::Promotional, 20, '2026-11-01'),
    ]);

    $first = $this->allocator->allocate($lots, 45)
        ->map(fn ($a): array => [$a->lot->id, $a->amount])->all();

    // Shuffled input must not change the outcome.
    $second = $this->allocator->allocate($lots->shuffle(), 45)
        ->map(fn ($a): array => [$a->lot->id, $a->amount])->all();

    expect($second)->toBe($first)
        ->and($first)->toBe([[2, 20], [3, 20], [1, 5]]);
});

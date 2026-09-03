<?php

declare(strict_types=1);

namespace App\Domain\Credit\Services;

use App\Domain\Credit\ValueObjects\CreditAllocation;
use App\Models\CreditLot;
use Illuminate\Support\Collection;

/**
 * Decides which lots a debit draws from.
 *
 * The order is fixed and deterministic, so the same wallet state always
 * produces the same allocation. That matters for more than tidiness: a
 * customer disputing a spend must get the same answer every time it is
 * recalculated.
 *
 * Priority, in order:
 *
 *   1. Source. Promotional credits go first, then referral, then adjustment,
 *      then purchased. Promotional credits are the ones that expire, so
 *      spending them first is the outcome that favours the customer;
 *      purchased credits -- the ones actually paid for -- are preserved
 *      longest.
 *
 *   2. Expiry, soonest first. Within a source, credits about to lapse are
 *      spent before credits that will not.
 *
 *   3. Lots with no expiry come after lots that have one, since they can
 *      never be lost by waiting.
 *
 *   4. Oldest lot first, by id, to break any remaining tie.
 */
class CreditAllocationService
{
    /**
     * Order a set of lots for consumption.
     *
     * Takes already-loaded lots rather than querying, because the caller must
     * lock them first -- and must lock them in id order, which is not the
     * order they are consumed in. Separating the two is what keeps locking
     * deadlock-free while consumption stays business-ordered.
     *
     * @param  Collection<int, CreditLot>  $lots
     * @return Collection<int, CreditLot>
     */
    public function order(Collection $lots): Collection
    {
        // One comparator over a composite key rather than a list of sort
        // callbacks: PHP compares arrays element by element, so this expresses
        // all four tie-breakers exactly, in order, with no ambiguity about how
        // the collection helper interprets multiple keys.
        return $lots
            ->sort(fn (CreditLot $a, CreditLot $b): int => $this->sortKey($a) <=> $this->sortKey($b))
            ->values();
    }

    /**
     * @return array{int, bool, int, int}
     */
    private function sortKey(CreditLot $lot): array
    {
        return [
            $lot->source_type->consumptionPriority(),
            // false sorts before true, so lots with an expiry date come before
            // lots without one.
            $lot->expires_at === null,
            $lot->expires_at?->getTimestamp() ?? 0,
            $lot->id,
        ];
    }

    /**
     * Work out how much to take from each lot, without changing anything.
     *
     * Returns an empty collection if the lots cannot cover the amount; the
     * caller decides whether that is an error, so this stays a pure
     * calculation.
     *
     * @param  Collection<int, CreditLot>  $lots
     * @return Collection<int, CreditAllocation>
     */
    public function allocate(Collection $lots, int $amount): Collection
    {
        if ($amount <= 0) {
            return collect();
        }

        $remaining = $amount;

        /** @var Collection<int, CreditAllocation> $allocations */
        $allocations = collect();

        foreach ($this->order($lots) as $lot) {
            if ($remaining === 0) {
                break;
            }

            if ($lot->remaining_amount <= 0) {
                continue;
            }

            $take = min($lot->remaining_amount, $remaining);

            $allocations->push(new CreditAllocation($lot, $take));

            $remaining -= $take;
        }

        // Not enough credit. Returning nothing rather than a partial plan
        // prevents a caller from accidentally applying half a debit.
        return $remaining > 0 ? collect() : $allocations;
    }

    /**
     * Total spendable credit held across the given lots.
     *
     * @param  Collection<int, CreditLot>  $lots
     */
    public function available(Collection $lots): int
    {
        return (int) $lots->sum('remaining_amount');
    }
}

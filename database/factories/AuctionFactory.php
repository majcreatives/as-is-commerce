<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\ValueObjects\AuctionSnapshot;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Auction>
 *
 * Builds an auction row directly, for tests about a state rather than about
 * how that state is reached.
 *
 * IT DOES NOT PUBLISH. Nothing here reserves inventory, because reserving is
 * part of publishing and publishing is a domain act. A test that cares about
 * inventory -- Buy Now, settlement, the races between them -- should create
 * the auction and then open it through
 * {@see AuctionLifecycle}, which is what the
 * `liveAuction()` test helper does.
 *
 * The settlement amount defaults to a low figure on purpose. It is deliberately
 * unrelated to the product's Buy Now price: a factory that derived one from the
 * other would quietly encode exactly the coupling the model forbids.
 */
class AuctionFactory extends Factory
{
    protected $model = Auction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory();
        $ruleset = AuctionRuleset::factory();

        return [
            'product_id' => $product,
            'auction_ruleset_id' => $ruleset,
            'settlement_amount_minor' => 10_000,
            'currency' => 'GHS',
            'status' => AuctionStatus::Draft,
            'bid_count' => 0,
            'extensions_applied' => 0,
            'extension_seconds_applied' => 0,
        ];
    }

    /**
     * Build the frozen snapshot once the ruleset and amount are known.
     *
     * Done in `configure` rather than `definition` because the ruleset may
     * arrive as a factory to be resolved, a model, or an id, and the snapshot
     * has to be taken from the row that actually ends up attached.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Auction $auction): void {
            if (isset($auction->rules_snapshot)) {
                return;
            }

            $ruleset = $auction->auction_ruleset_id === null
                ? AuctionRuleset::factory()->create()
                : AuctionRuleset::findOrFail($auction->auction_ruleset_id);

            $snapshot = new AuctionSnapshot(
                rules: $ruleset->toRules(),
                settlementAmount: Money::fromMinor($auction->settlement_amount_minor, $auction->currency),
            );

            $auction->rules_snapshot = $snapshot->toArray();
            $auction->snapshot_version = AuctionSnapshot::SNAPSHOT_VERSION;
        });
    }

    /**
     * Every column is guarded or lifecycle state, so the model has no
     * fillable attributes at all. The factory writes them directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Auction
    {
        $auction = new Auction;
        $auction->forceFill($attributes);

        return $auction;
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->id]);
    }

    public function usingRuleset(AuctionRuleset $ruleset): static
    {
        return $this->state(fn (): array => ['auction_ruleset_id' => $ruleset->id]);
    }

    /**
     * What a normal highest-bid winner will pay, in pesewas.
     */
    public function settlingAt(int $minor): static
    {
        return $this->state(fn (): array => ['settlement_amount_minor' => $minor]);
    }

    /**
     * Open, with the clock already running.
     */
    public function live(int $secondsRemaining = 3_600): static
    {
        return $this->state(fn (): array => [
            'status' => AuctionStatus::Live,
            'starts_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->addSeconds($secondsRemaining),
        ]);
    }

    public function closing(int $secondsRemaining = 10): static
    {
        return $this->state(fn (): array => [
            'status' => AuctionStatus::Closing,
            'starts_at' => Carbon::now()->subMinute(),
            'ends_at' => Carbon::now()->addSeconds($secondsRemaining),
            'closing_started_at' => Carbon::now(),
        ]);
    }

    public function scheduled(int $secondsAhead = 3_600): static
    {
        return $this->state(fn (): array => [
            'status' => AuctionStatus::Scheduled,
            'scheduled_start_at' => Carbon::now()->addSeconds($secondsAhead),
        ]);
    }

    /**
     * Open, but past its end time -- the gap between the clock running out
     * and the sweep noticing.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => AuctionStatus::Live,
            'starts_at' => Carbon::now()->subHour(),
            'ends_at' => Carbon::now()->subMinute(),
        ]);
    }
}

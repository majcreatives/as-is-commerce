<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auctions;

use App\Domain\Auction\Actions\CancelAuction;
use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\RelistAuction;
use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderSource;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One auction, as an administrator sees it.
 *
 * WHAT THIS SCREEN CANNOT DO. It cannot edit a live auction's rules. There is
 * no form for it, because there is no code path for it: the snapshot is frozen
 * by the model and again by a database trigger. An auction's terms are what
 * people are bidding under, and offering an edit box that always failed would
 * be worse than not offering one.
 *
 * It also cannot name a winner, adjust a bid, or mark a settlement paid.
 * Winners are resolved from the bid records when the auction closes, bids are
 * append-only, and taking the winner's payment belongs to the settlement
 * stage.
 *
 * What it can do is publish, close early, cancel, and relist -- each of which
 * is a lifecycle transition with a recorded reason.
 */
#[Layout('components.layouts.app')]
class AuctionDetail extends Component
{
    public Auction $auction;

    public string $scheduledStart = '';

    public string $cancelReason = '';

    public string $relistSettlementAmount = '';

    public ?int $relistRulesetId = null;

    public function mount(Auction $auction): void
    {
        $this->authorize('auctions.view');

        $this->auction = $auction;
        $this->relistSettlementAmount = $auction->settlementAmount()->toDecimalString();
        $this->relistRulesetId = AuctionRuleset::query()->active()
            ->orderByDesc('is_default')->value('id');
    }

    /**
     * Open the auction now.
     */
    public function start(AuctionLifecycle $lifecycle): void
    {
        $this->authorize('auctions.publish');

        $this->attempt(fn () => $lifecycle->start($this->auction, auth()->user()));
    }

    /**
     * Publish it to open at a chosen time.
     */
    public function schedule(AuctionLifecycle $lifecycle): void
    {
        $this->authorize('auctions.publish');

        $validated = $this->validate([
            'scheduledStart' => ['required', 'date', 'after:now'],
        ], [
            'scheduledStart.after' => 'A scheduled auction has to open in the future.',
        ]);

        $this->attempt(fn () => $lifecycle->schedule(
            $this->auction,
            Carbon::parse($validated['scheduledStart']),
            auth()->user(),
        ));
    }

    /**
     * Close it now rather than waiting for the clock.
     *
     * The winner is still resolved from the bid records in the ordinary way:
     * closing early changes when the auction stops, never who won.
     */
    public function closeNow(CloseAuction $close): void
    {
        $this->authorize('auctions.publish');

        $this->attempt(fn () => $close->handle($this->auction, force: true));
    }

    public function cancel(CancelAuction $cancel): void
    {
        $this->authorize('auctions.cancel');

        $validated = $this->validate([
            // A reason is required: an auction stopped without explanation is
            // indistinguishable from a mistake when someone reviews it later,
            // and bidders whose credits are gone deserve one.
            'cancelReason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        // Through the action, which also closes any settlement checkout the
        // winner was holding -- a cancelled auction releases its unit, and a
        // payable order pointing at it would be racing whoever buys it next.
        $this->attempt(fn () => $cancel->handle(
            $this->auction,
            $validated['cancelReason'],
            auth()->user(),
        ));

        $this->cancelReason = '';
    }

    public function relist(RelistAuction $relist): void
    {
        $this->authorize('auctions.relist');

        $validated = $this->validate([
            'relistRulesetId' => ['required', 'integer', 'exists:auction_rulesets,id'],
            'relistSettlementAmount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ], [
            'relistSettlementAmount.regex' => 'Enter an amount in cedis, such as 100 or 100.50.',
        ]);

        try {
            $replacement = $relist->handle(
                original: $this->auction,
                ruleset: AuctionRuleset::findOrFail($validated['relistRulesetId']),
                settlementAmount: Money::fromDecimalString($validated['relistSettlementAmount']),
                actor: auth()->user(),
            );
        } catch (DomainException $e) {
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        $this->redirectRoute('admin.auctions.show', $replacement, navigate: true);
    }

    /**
     * Run a lifecycle change, reporting a refusal rather than failing silently.
     */
    private function attempt(callable $work): void
    {
        try {
            $work();
        } catch (DomainException $e) {
            $this->addError('lifecycle', $e->getMessage());

            return;
        }

        $this->auction->refresh();
    }

    public function render(HighestBidResolver $bids, AuctionClock $clock): View
    {
        $this->auction->refresh();

        return view('livewire.admin.auctions.auction-detail', [
            // The authoritative reading beside the cached one, so a
            // discrepancy is visible here rather than silently repaired.
            'highestBid' => $bids->highestBid($this->auction),
            'projection' => $bids->verify($this->auction),
            'history' => $bids->history($this->auction, 100),
            'secondsRemaining' => $clock->secondsRemaining($this->auction),
            'rulesets' => AuctionRuleset::query()->active()->orderBy('name')->get(),
            // The winner's settlement checkout, and every Buy Now order opened
            // against this auction. Several of the latter can exist at once --
            // opening one reserves nothing -- so operations needs to see which
            // one actually acquired the product and which were blocked.
            'settlementOrder' => $this->auction->settlementOrder()->with('successfulPayment')->first(),
            'buyNowOrders' => $this->auction->orders()
                ->where('source', OrderSource::BuyNow)
                ->with('user')
                ->orderByDesc('id')
                ->get(),
        ])->title('Auction #'.$this->auction->id);
    }
}
